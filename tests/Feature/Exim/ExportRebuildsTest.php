<?php

namespace Tests\Feature\Exim;

use App\Models\BackgroundTask;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Exim\ExportRunner;
use Torgash\Exim\Fingerprint;
use Torgash\Exim\Jobs\BuildExport;
use Torgash\Exim\Models\EximProfile;
use Torgash\Exim\Rebuilds;
use Torgash\Guard\Models\AuditEntry;

/**
 * An export file that keeps itself current (PLAN §59): rebuilt at the times
 * its cron schedule names, and when what it reads has changed — but not
 * sooner than its rest after the last build, and never for a page view. One
 * build per profile, only the newest file kept, and a feed that rebuilds
 * itself is handed out as it stands however often it is fetched.
 */
class ExportRebuildsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function profile(array $extra = []): EximProfile
    {
        return EximProfile::create(['type' => 'export', 'name' => ['en' => 'Feed'], 'entity' => 'product', 'format' => 'csv',
            'mode' => 'schema', 'public_token' => bin2hex(random_bytes(16)), 'enabled' => true, ...$extra]);
    }

    protected function product(string $sku): Product
    {
        return Product::create(['sku' => $sku, 'name' => [content_base_locale() => $sku], 'slug' => strtolower($sku),
            'price' => 100_00, 'stock' => 5, 'status' => 'active']);
    }

    /** @return list<array{0: int, 1: string}> the due profiles among these */
    protected function due(EximProfile ...$profiles): array
    {
        $ids = array_map(fn (EximProfile $profile) => $profile->id, $profiles);

        return collect(app(Rebuilds::class)->due())
            ->filter(fn (array $due) => in_array($due[0]->id, $ids, true))
            ->map(fn (array $due) => [$due[0]->id, $due[1]])
            ->values()
            ->all();
    }

    public function test_a_change_makes_the_file_due_after_its_rest_and_a_page_view_does_not(): void
    {
        $product = $this->product('REBUILD-1');
        $profile = $this->profile(['rebuild_on_change' => true, 'rebuild_every' => 30]);

        $this->travel(5)->seconds();
        app(ExportRunner::class)->run($profile);
        $this->assertNotNull($profile->refresh()->built_fingerprint, 'a settled catalogue has a fingerprint');

        $this->travel(31)->minutes();
        $this->assertSame([], $this->due($profile), 'nothing changed, nothing is built');

        // How Popularity writes a visit: the row, never its updated_at.
        DB::table('m_catalog_products')->where('id', $product->id)->increment('popularity', 1);
        $this->travel(5)->seconds();
        $this->assertSame([], $this->due($profile), 'a page view is not a change of the feed');

        $product->update(['price' => 120_00]);
        $this->travel(5)->seconds();
        $this->assertSame([[$profile->id, Rebuilds::CHANGE]], $this->due($profile));

        // Built again — and then a change waits out the rest.
        app(ExportRunner::class)->run($profile);
        $this->travel(1)->minutes();
        $product->update(['price' => 130_00]);
        $this->travel(5)->seconds();
        $this->assertSame([], $this->due($profile), 'rebuilt a minute ago: the change waits');

        $this->travel(30)->minutes();
        $this->assertSame([[$profile->id, Rebuilds::CHANGE]], $this->due($profile));
    }

    public function test_a_catalogue_being_written_this_second_counts_as_changed(): void
    {
        $this->product('REBUILD-HOT');

        $this->assertNull(Fingerprint::of('product'), 'cannot tell yet — so "changed", never "the same"');

        $this->travel(5)->seconds();
        $this->assertNotNull(Fingerprint::of('product'));
    }

    public function test_a_product_moved_to_another_category_changes_the_fingerprint(): void
    {
        // The pivot has no updated_at, and the product row does not move.
        hook_listen('exim.watch.pivot_probe', fn (array $tables) => [...$tables, 'm_catalog_category_product']);
        $base = content_base_locale();
        $from = Category::create(['name' => [$base => 'From'], 'slug' => 'rebuild-from', 'status' => 'active']);
        $to = Category::create(['name' => [$base => 'To'], 'slug' => 'rebuild-to', 'status' => 'active']);
        $product = $this->product('REBUILD-MOVE');
        $product->categories()->attach($from->id);

        $before = Fingerprint::of('pivot_probe');
        $product->categories()->sync([$to->id]);
        $after = Fingerprint::of('pivot_probe');

        $this->assertNotNull($before);
        $this->assertNotSame($before, $after, 'same count, other rows');
        $this->assertSame($after, Fingerprint::of('pivot_probe'), 'and stable while nothing moves');
    }

    public function test_a_schedule_is_due_once_for_each_time_it_names(): void
    {
        $this->travelTo(Carbon::parse('2030-01-15 02:59:30'));
        $profile = $this->profile(['schedule' => '0 3 * * *']);
        $this->assertSame([[$profile->id, Rebuilds::SCHEDULE]], $this->due($profile), 'never built: built now');

        $profile->forceFill(['last_run_at' => Carbon::parse('2030-01-14 03:00:00')])->saveQuietly();
        $this->assertSame([], $this->due($profile));

        $this->travelTo(Carbon::parse('2030-01-15 03:00:10'));
        $this->assertSame([[$profile->id, Rebuilds::SCHEDULE]], $this->due($profile), 'kept to the minute');

        // A build that started at 03:00:00.4 is stored as 03:00:00 — that minute is served.
        $profile->forceFill(['last_run_at' => Carbon::parse('2030-01-15 03:00:00')])->saveQuietly();
        $this->travelTo(Carbon::parse('2030-01-15 03:01:00'));
        $this->assertSame([], $this->due($profile));

        $this->travelTo(Carbon::parse('2030-01-16 03:00:05'));
        $this->assertSame([[$profile->id, Rebuilds::SCHEDULE]], $this->due($profile));
    }

    public function test_one_profile_never_has_two_builds_waiting(): void
    {
        Queue::fake();
        $profile = $this->profile(['rebuild_on_change' => true]);

        $this->assertNotNull(app(Rebuilds::class)->start($profile, Rebuilds::CHANGE));
        $this->assertNull(app(Rebuilds::class)->start($profile->fresh(), Rebuilds::CHANGE));

        Queue::assertPushed(BuildExport::class, 1);
        $this->assertTrue($profile->fresh()->isBuilding());
    }

    public function test_the_background_build_writes_the_file_and_says_why(): void
    {
        $this->product('REBUILD-JOB');
        $profile = $this->profile(['rebuild_on_change' => true]);

        $task = app(Rebuilds::class)->start($profile, Rebuilds::CHANGE);

        $task->refresh();
        $this->assertSame(BackgroundTask::DONE, $task->status, (string) $task->error);
        $profile->refresh();
        $this->assertNotNull(app(ExportRunner::class)->current($profile));
        $this->assertGreaterThan(0, $profile->last_rows);
        $this->assertGreaterThan(0, $profile->last_size);
        $this->assertFalse($profile->isBuilding());
        $this->assertSame($profile->last_rows, $task->result['rows']);
    }

    public function test_only_the_newest_file_is_kept(): void
    {
        $profile = $this->profile();

        app(ExportRunner::class)->run($profile);
        $this->travel(2)->seconds();
        $newest = app(ExportRunner::class)->run($profile);

        $this->assertSame([$newest], Storage::disk('local')->files('exim/'.$profile->id));
        $this->assertSame($newest, $profile->fresh()->last_file_path);
    }

    public function test_a_file_that_rebuilds_itself_is_handed_out_as_it_stands(): void
    {
        $product = $this->product('REBUILD-FEED');
        $profile = $this->profile(['rebuild_on_change' => true]);
        app(ExportRunner::class)->run($profile);
        $built = $profile->refresh()->last_run_at;

        $this->travel(1)->minutes();
        $product->update(['sku' => 'REBUILD-FEED-2']);

        $this->get('http://localhost/exim/feed/'.$profile->public_token)
            ->assertOk()
            ->assertDontSee('REBUILD-FEED-2');
        $this->assertEquals($built, $profile->fresh()->last_run_at, 'a fetch is not a build');
    }

    public function test_a_file_on_request_is_built_for_the_fetch(): void
    {
        $product = $this->product('ON-REQUEST');
        $profile = $this->profile();

        $this->get('http://localhost/exim/feed/'.$profile->public_token)->assertOk();
        $first = $profile->fresh()->last_run_at;
        $this->assertNotNull($first);

        $this->travel(1)->minutes();
        $product->update(['sku' => 'ON-REQUEST-2']);
        $this->get('http://localhost/exim/feed/'.$profile->public_token)->assertOk();

        $this->assertTrue($profile->fresh()->last_run_at->gt($first));
        $this->assertStringContainsString('ON-REQUEST-2', Storage::disk('local')->get($profile->fresh()->last_file_path));
    }

    public function test_a_build_is_not_an_edit_in_the_audit_trail(): void
    {
        config(['guard.audit.enabled' => true, 'guard.audit.ignore' => []]);
        $profile = $this->profile();
        AuditEntry::query()->delete();

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/exim/profiles/'.$profile->id.'/run')
            ->assertOk();

        $this->assertNotNull($profile->fresh()->last_run_at);
        $this->assertFalse(AuditEntry::where('entity_type', EximProfile::class)->exists());
    }

    public function test_a_deleted_profile_takes_its_files(): void
    {
        $profile = $this->profile();
        app(ExportRunner::class)->run($profile);
        $this->assertNotSame([], Storage::disk('local')->files('exim/'.$profile->id));

        $profile->delete();

        $this->assertFalse(Storage::disk('local')->exists('exim/'.$profile->id));
    }

    public function test_a_schedule_is_cron_typed_or_picked_and_a_wrong_one_is_refused(): void
    {
        $profile = $this->profile();
        $this->actingAs(User::role('admin')->firstOrFail());
        $save = fn (array $fields) => $this->put('http://localhost/admin/exim/profiles/'.$profile->id, [
            'name' => 'Feed', 'entity' => 'product', 'format' => 'csv', 'mode' => 'schema', 'enabled' => '1', ...$fields,
        ]);

        $save(['schedule' => 'every day'])->assertSessionHasErrors('schedule');
        $this->assertNull($profile->fresh()->schedule);

        $save(['schedule' => '0  5 * * *', 'rebuild_on_change' => '1', 'rebuild_every' => '60'])->assertSessionHasNoErrors();
        $this->assertSame('0 5 * * *', $profile->fresh()->schedule);
        $this->assertTrue($profile->fresh()->rebuild_on_change);
        $this->assertSame(60, $profile->fresh()->rebuild_every);

        // The word this field took before cron keeps its time.
        $save(['schedule' => 'daily'])->assertSessionHasNoErrors();
        $this->assertSame('0 0 * * *', $profile->fresh()->schedule);
        $this->assertFalse($profile->fresh()->rebuild_on_change);

        $save(['rebuild_every' => '7'])->assertSessionHasErrors('rebuild_every');
    }

    public function test_the_line_under_the_schedule_names_the_next_builds(): void
    {
        $this->travelTo(Carbon::parse('2030-01-15 10:00:00'));
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->get('http://localhost/admin/exim/profiles/schedule?schedule='.urlencode('0 3 * * *'))
            ->assertOk()
            ->assertSee('16.01 03:00, 17.01 03:00, 18.01 03:00');
        $this->get('http://localhost/admin/exim/profiles/schedule?schedule=nonsense')
            ->assertOk()
            ->assertSee('Cron cannot read this', false);
    }

    public function test_the_form_shows_how_the_file_is_kept_current(): void
    {
        $profile = $this->profile(['schedule' => '0 3 * * *', 'rebuild_on_change' => true]);
        app(ExportRunner::class)->run($profile);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/exim/profiles/'.$profile->id.'/edit')
            ->assertOk()
            ->assertSee('Updating the file')
            ->assertSee('Every day at 03:00')
            ->assertSee('Next by the schedule:', false)
            ->assertSee('Built ', false);
    }
}
