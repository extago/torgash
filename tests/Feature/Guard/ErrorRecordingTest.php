<?php

namespace Tests\Feature\Guard;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;
use Torgash\Guard\Models\ErrorGroup;

/**
 * The first part of the guard: making a shop able to learn that something
 * broke.
 *
 * A 500 on the storefront goes to `storage/logs/laravel.log`, which a shop
 * owner will never open — so as far as the shop is concerned it did not happen.
 * Everything pinned here is about the two ways this feature fails: by being
 * useless (one row per occurrence, so the screen is a wall) and by being
 * dangerous (throwing, or looping, on the one path where things are already
 * going wrong).
 */
class ErrorRecordingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(SettingsManager::class);
        $settings->set('torgash.guard', 'errors_enabled', true);
        $settings->set('torgash.guard', 'errors_level', 'error');
        $settings->set('torgash.guard', 'errors_ignore', '');

        ErrorGroup::query()->delete();
    }

    // -------------------------------------------------------------- recording

    public function test_an_exception_is_recorded_with_where_it_was_thrown(): void
    {
        $exception = new RuntimeException('Что-то пошло не так');

        Log::error($exception->getMessage(), ['exception' => $exception]);

        $group = ErrorGroup::firstOrFail();

        $this->assertSame(RuntimeException::class, $group->class);
        $this->assertSame('Что-то пошло не так', $group->message);
        $this->assertSame(__FILE__, $group->file);
        $this->assertSame(1, $group->count);
        $this->assertNotEmpty($group->context['trace'] ?? []);
    }

    /**
     * The decision the whole screen rests on: one row per KIND of failure with
     * a counter, never one per occurrence. A thousand identical 500s must be a
     * line saying "1000 times", not a thousand lines.
     */
    public function test_the_same_failure_twice_is_one_row_with_a_count(): void
    {
        foreach (range(1, 3) as $ignored) {
            $exception = new RuntimeException('Один и тот же сбой');
            Log::error($exception->getMessage(), ['exception' => $exception]);
        }

        $this->assertSame(1, ErrorGroup::count());
        $this->assertSame(3, ErrorGroup::firstOrFail()->count);
    }

    /**
     * A message carrying an id is one problem, not one problem per customer.
     * Digits only: guessing more starts merging things that are genuinely
     * different, and the rule has to fit in a sentence.
     */
    public function test_messages_differing_only_by_a_number_are_one_group(): void
    {
        Log::error('Заказ 512 не найден');
        Log::error('Заказ 4096 не найден');

        $this->assertSame(1, ErrorGroup::count());
        $this->assertSame(2, ErrorGroup::firstOrFail()->count);
    }

    /** Two different bugs in two different places stay two rows. */
    public function test_different_failures_stay_apart(): void
    {
        Log::error('Первое', ['exception' => new RuntimeException('a')]);
        Log::error('Второе', ['exception' => new DomainException('b')]);

        $this->assertSame(2, ErrorGroup::count());
    }

    /** The last occurrence wins for context: "what was happening the last time". */
    public function test_the_context_is_a_sample_of_the_most_recent_occurrence(): void
    {
        Log::error('Повторяется', ['where' => 'first']);
        Log::error('Повторяется', ['where' => 'second']);

        $this->assertSame('second', ErrorGroup::firstOrFail()->context['extra']['where']);
    }

    /**
     * Objects in the caller's context are dropped rather than followed: they are
     * models, requests and containers, and json_encode over one of those is
     * either a crash or a megabyte.
     */
    public function test_objects_in_the_context_are_not_stored(): void
    {
        Log::error('С объектом', ['user' => new User(['name' => 'Иван']), 'order_number' => 'TG-000001']);

        $extra = ErrorGroup::firstOrFail()->context['extra'] ?? [];

        $this->assertSame(['order_number' => 'TG-000001'], $extra);
    }

    // ---------------------------------------------------------- what to record

    public function test_anything_below_the_configured_level_is_dropped(): void
    {
        Log::warning('Просто предупреждение');
        Log::error('Настоящая ошибка');

        $this->assertSame(1, ErrorGroup::count());
        $this->assertSame('error', ErrorGroup::firstOrFail()->level);

        app(SettingsManager::class)->set('torgash.guard', 'errors_level', 'warning');

        Log::warning('Просто предупреждение');

        $this->assertSame(2, ErrorGroup::count());
    }

    public function test_the_ignore_list_is_matched_against_message_and_class(): void
    {
        app(SettingsManager::class)->set('torgash.guard', 'errors_ignore', "устаревш\nDomainException");

        Log::error('Вызов устаревшего метода');
        Log::error('Другое', ['exception' => new DomainException('x')]);
        Log::error('Настоящая ошибка');

        $this->assertSame(1, ErrorGroup::count());
        $this->assertSame('Настоящая ошибка', ErrorGroup::firstOrFail()->message);
    }

    public function test_it_records_nothing_when_switched_off(): void
    {
        app(SettingsManager::class)->set('torgash.guard', 'errors_enabled', false);

        Log::error('Никуда не попадёт');

        $this->assertSame(0, ErrorGroup::count());
    }

    // ------------------------------------------------------- not making it worse

    /**
     * The one that matters most. The ordinary reason recording fails is that
     * the DATABASE is unavailable — and the write's own failure is logged,
     * which arrives back here, which tries the same write. An infinite loop on
     * the single path where the shop is already in trouble.
     *
     * Simulated by taking the table away inside the test's own transaction: the
     * caller must not see an exception, and the request must not hang.
     */
    public function test_a_failure_to_record_never_reaches_the_caller(): void
    {
        DB::statement('drop table m_guard_errors');

        Log::error('Ошибка во время того, как всё уже сломано');

        // Getting here at all is the assertion: no exception escaped, and
        // nothing looped.
        $this->assertTrue(true);
    }

    /** A problem that comes back has to come back onto the list. */
    public function test_a_resolved_error_reopens_when_it_happens_again(): void
    {
        Log::error('Вернётся');

        ErrorGroup::query()->update(['resolved_at' => now()]);

        Log::error('Вернётся');

        $group = ErrorGroup::firstOrFail();

        $this->assertNull($group->resolved_at);
        $this->assertSame(2, $group->count);
    }

    // -------------------------------------------------------------- the screen

    public function test_the_screen_lists_open_errors_and_can_resolve_one(): void
    {
        Log::error('Видно в админке');

        $group = ErrorGroup::firstOrFail();

        $this->actingAs($this->admin())
            ->get('http://localhost/admin/guard/errors')
            ->assertOk()
            ->assertSee('Видно в админке');

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/guard/errors/'.$group->id.'/resolve')
            ->assertRedirect();

        $this->assertNotNull($group->fresh()->resolved_at);

        // Gone from the open list, and findable on the other tab.
        $this->actingAs($this->admin())
            ->get('http://localhost/admin/guard/errors')
            ->assertDontSee('Видно в админке');

        $this->actingAs($this->admin())
            ->get('http://localhost/admin/guard/errors?resolved=1')
            ->assertSee('Видно в админке');
    }

    /**
     * Every broken query in the shop is thrown on the same line of Laravel's
     * Connection. Grouped by that line they were ONE row pointing at a file
     * nobody here can change; grouped by where our code asked, they are two.
     */
    public function test_failures_are_grouped_and_located_by_the_shops_own_code(): void
    {
        // Two different lines of ours asking; the helper only records.
        $this->brokenQuery(fn () => DB::select('select no_such_column_one'));
        $this->brokenQuery(fn () => DB::select('select no_such_column_two'));

        $this->assertSame(2, ErrorGroup::count());

        foreach (ErrorGroup::all() as $group) {
            $this->assertSame(__FILE__, $group->file);
            $this->assertStringNotContainsString('vendor', $group->shortFile());
            $this->assertStringContainsString('vendor', (string) $group->thrownAt());
        }
    }

    public function test_a_row_whose_code_changed_since_is_offered_as_probably_fixed(): void
    {
        $stale = ErrorGroup::create(['fingerprint' => sha1('stale'), 'level' => 'error', 'message' => 'Old', 'file' => __FILE__, 'line' => 1,
            'count' => 1, 'first_seen_at' => '2000-01-01', 'last_seen_at' => '2000-01-01']);
        $fresh = ErrorGroup::create(['fingerprint' => sha1('fresh'), 'level' => 'error', 'message' => 'Fresh', 'file' => __FILE__, 'line' => 1,
            'count' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()->addDay()]);
        $gone = ErrorGroup::create(['fingerprint' => sha1('gone'), 'level' => 'error', 'message' => 'Gone', 'file' => base_path('modules/no-such-file.php'), 'line' => 1,
            'count' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->assertSame('changed', $stale->codeState());
        $this->assertNull($fresh->codeState());
        $this->assertSame('gone', $gone->codeState());

        // A row recorded before the recorder knew (a framework file) is placed
        // by the first of our frames in its stored trace.
        $legacy = new ErrorGroup(['file' => base_path('vendor/laravel/framework/src/Illuminate/Database/Connection.php'), 'line' => 857, 'message' => 'x',
            'context' => ['trace' => [base_path('vendor/x.php').':1 A->b()', base_path('modules/torgash.locations/src/Http/Admin/PlacesController.php').':42 C->d()']]]);
        $this->assertSame('modules/torgash.locations/src/Http/Admin/PlacesController.php', $legacy->shortFile());
        $this->assertSame(42, $legacy->originLine());

        $this->actingAs($this->admin())->get('http://localhost/admin/guard/errors?tab=changed')->assertOk()
            ->assertSee('Old')->assertDontSee('Fresh');

        $this->actingAs($this->admin())->post('http://localhost/admin/guard/errors/bulk', ['action' => 'resolve', 'ids' => [$stale->id, $gone->id]])
            ->assertRedirect();
        $this->assertNotNull($stale->fresh()->resolved_at);
        $this->assertNotNull($gone->fresh()->resolved_at);
        $this->assertNull($fresh->fresh()->resolved_at);
    }

    /** A failing query inside a savepoint, so the test's own transaction survives it. */
    protected function brokenQuery(\Closure $query): void
    {
        try {
            DB::transaction($query);
        } catch (\Illuminate\Database\QueryException $exception) {
            Log::error($exception->getMessage(), ['exception' => $exception]);
        }
    }

    public function test_the_screen_is_behind_its_own_permission(): void
    {
        $nobody = User::create(['name' => 'Никто', 'email' => 'nobody@example.test', 'password' => 'secret123']);

        $this->actingAs($nobody)
            ->get('http://localhost/admin/guard/errors')
            ->assertForbidden();
    }

    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }
}
