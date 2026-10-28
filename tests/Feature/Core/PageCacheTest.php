<?php

namespace Tests\Feature\Core;

use App\Core\PageCache\PageCache;
use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Whole pages kept ready for guests (PLAN §61), on pages of its own: a copy
 * is taken and served; the form token and the script nonce are every
 * visitor's own; personal parts are drawn for each visitor, or kept as drawn
 * for whoever holds what they read; a page that reads or writes what it must
 * not is not kept, and says why; writes throw copies away once committed,
 * tags only theirs; varies file copies apart; an advert's address is served
 * but never copied; a visit action runs on every visit.
 */
class PageCacheTest extends TestCase
{
    protected int $visits = 0;

    protected function setUp(): void
    {
        parent::setUp();

        View::addNamespace('pctest', base_path('tests/fixtures/views/pagecache'));
        $this->app->instance('pctest.draws', new class
        {
            public int $secret = 0;

            public int $note = 0;

            public int $copies = 0;
        });

        page_cache()
            ->kind('pctest', 'Test pages')
            ->personal(session: ['pctest.secret', 'pctest.note'])
            ->personal(session: ['pctest.copies'], copies: true)
            ->harmless(session: ['pctest.harmless'])
            ->vary('pctest_flavour', fn (Request $request) => (string) $request->session()->get('pctest.flavour', 'plain'), session: ['pctest.flavour'])
            ->onVisit('pctest.visit', function () {
                $this->visits++;
            });

        Route::middleware(['web', 'page.cache:pctest'])->group(function () {
            Route::get('/pc-test/page', fn () => view('pctest::page'));
            Route::get('/pc-test/leaky', fn () => view('pctest::leaky'));
            Route::get('/pc-test/writer', fn () => view('pctest::writer'));
            Route::get('/pc-test/pusher', fn () => view('pctest::pushpage'));
            Route::get('/pc-test/escaped', fn () => view('pctest::escaped'));
            Route::get('/pc-test/broken', fn () => abort(404));
            Route::get('/pc-test/tagged/{n}', function (int $n) {
                page_cache()->tag('pctest:'.$n);

                return view('pctest::page', ['body' => 'tagged '.$n]);
            });
            Route::get('/pc-test/stale', function () {
                page_cache()->until(now()->subMinute());

                return view('pctest::page');
            });
            Route::get('/pc-test/soon', function () {
                page_cache()->until(now()->addMinutes(5));

                return view('pctest::page');
            });
            Route::get('/pc-test/visited', function () {
                visit_action('pctest.visit');

                return view('pctest::page');
            });
            Route::get('/pc-test/cookie', fn () => response(view('pctest::page'))->cookie('pctest_cookie', 'x'));
        });

        Route::middleware(['web', 'page.cache:pctest,page'])->get('/pc-test/list', fn () => view('pctest::page', ['body' => 'page '.request('page', 1)]));

        app(SettingsManager::class)->set('core', PageCache::ENABLED, true);
        page_cache()->flush('a test begins');
    }

    /** A visitor nobody has seen: no session, no memo of the last one. */
    protected function newVisitor(): void
    {
        $this->flushSession();
    }

    /** What happened — without the reason APP_DEBUG adds after a semicolon. */
    protected function cache(TestResponse $response): ?string
    {
        $state = $response->headers->get('X-Torgash-Cache');

        return $state === null ? null : strtok($state, ';');
    }

    protected function draws(): object
    {
        return app('pctest.draws');
    }

    public function test_a_guest_page_is_kept_and_the_next_guest_is_served_the_copy(): void
    {
        $first = $this->get('/pc-test/page');
        $first->assertOk();
        $this->assertSame('stored', $this->cache($first));

        $this->newVisitor();
        $second = $this->get('/pc-test/page');
        $second->assertOk();
        $this->assertSame('hit', $this->cache($second));
        $second->assertSee('<div id="body">plain</div>', false);
        $this->assertDoesNotMatchRegularExpression('/pc-[0-9a-f]{16}-/', $second->getContent(), 'no placeholder or marker is left in a served page');
        $this->assertSame(1, (int) DB::table('page_cache')->count());
    }

    public function test_the_form_token_and_the_script_nonce_are_each_visitors_own(): void
    {
        $first = $this->get('/pc-test/page');
        preg_match('/name="csrf-token" content="([^"]+)"/', $first->getContent(), $token);
        preg_match('/<script nonce="([^"]+)">window.pcTest/', $first->getContent(), $nonce);

        $this->newVisitor();
        $second = $this->get('/pc-test/page');
        $this->assertSame('hit', $this->cache($second));

        $this->assertStringNotContainsString($token[1], $second->getContent(), 'the first visitor\'s token is not handed to the next');
        $this->assertStringNotContainsString($nonce[1], $second->getContent(), 'nor their nonce');
        $this->assertStringContainsString('content="'.session()->token().'"', $second->getContent(), 'the visitor\'s own token, in the meta');
        $this->assertStringContainsString('name="_token" value="'.session()->token().'"', $second->getContent(), '...and in the form');
        $this->assertMatchesRegularExpression('/<script nonce="[0-9a-f]+">window.pcTest/', $second->getContent());
        $this->assertStringContainsString("'nonce-".app(\App\Core\Http\Csp::class)->nonce()."'", (string) $second->headers->get('Content-Security-Policy').(string) $second->headers->get('Content-Security-Policy-Report-Only'), 'the nonce in the page is the one the policy allows');
    }

    public function test_a_personal_part_is_drawn_for_its_holder_and_kept_as_drawn_for_everybody_else(): void
    {
        $this->get('/pc-test/page')->assertSee('<b>nobody</b>', false);
        $this->assertSame(1, $this->draws()->secret);

        // Somebody holding the key is served the copy — their part drawn for them.
        $this->newVisitor();
        $mine = $this->withSession(['pctest.secret' => 'mine'])->get('/pc-test/page');
        $this->assertSame('hit', $this->cache($mine));
        $mine->assertSee('<b>mine</b>', false);
        $this->assertSame(2, $this->draws()->secret);

        // Nobody else gets it, and the part is not drawn again for them.
        $this->newVisitor();
        $other = $this->get('/pc-test/page');
        $other->assertSee('<b>nobody</b>', false)->assertDontSee('mine');
        $this->assertSame(2, $this->draws()->secret, 'kept as drawn: no drawing for a visitor who holds none of it');

        // A part that is not reusable is drawn for every visitor.
        $this->assertSame(3, $this->draws()->note);
    }

    public function test_a_visitor_holding_something_personal_gives_no_copy_and_gets_no_part_as_drawn(): void
    {
        $response = $this->withSession(['pctest.secret' => 'mine'])->get('/pc-test/page');
        $this->assertSame('miss', $this->cache($response));
        $this->assertSame(0, (int) DB::table('page_cache')->count());

        $this->newVisitor();
        $this->get('/pc-test/page');

        // The copies part reads only its own key — but a memo of the other
        // could sit behind it: nothing is reused for somebody holding one.
        $this->newVisitor();
        $before = $this->draws()->copies;
        $this->withSession(['pctest.secret' => 'mine'])->get('/pc-test/page');
        $this->assertSame($before + 1, $this->draws()->copies);
    }

    public function test_a_part_is_kept_as_drawn_for_whoever_holds_the_same_and_drawn_for_anybody_else(): void
    {
        // A visitor holding a `copies` key gives copies — its value goes with the part.
        $first = $this->withSession(['pctest.copies' => ['a', 'b']])->get('/pc-test/page');
        $first->assertSee('<u>a,b</u>', false);
        $this->assertSame('stored', $this->cache($first));
        $drawn = $this->draws()->copies;

        $this->newVisitor();
        $same = $this->withSession(['pctest.copies' => ['a', 'b']])->get('/pc-test/page');
        $this->assertSame('hit', $this->cache($same));
        $same->assertSee('<u>a,b</u>', false);
        $this->assertSame($drawn, $this->draws()->copies, 'the same value: kept as drawn');

        $this->newVisitor();
        $this->withSession(['pctest.copies' => ['c']])->get('/pc-test/page')->assertSee('<u>c</u>', false)->assertDontSee('a,b');
        $this->assertSame($drawn + 1, $this->draws()->copies, 'another value: drawn for them');

        $this->newVisitor();
        $this->get('/pc-test/page')->assertSee('<u></u>', false)->assertDontSee('a,b');
    }

    public function test_a_page_reading_personal_state_outside_a_part_is_not_kept_and_says_why(): void
    {
        $response = $this->get('/pc-test/leaky');
        $response->assertOk();
        $this->assertSame('miss', $this->cache($response));
        $this->assertSame(0, (int) DB::table('page_cache')->count());
        $this->assertStringContainsString('pctest.secret outside a personal part', implode("\n", array_column(page_cache()->refusals(), 'reason')));
    }

    public function test_a_page_writing_the_session_is_not_kept(): void
    {
        $this->assertSame('miss', $this->cache($this->get('/pc-test/writer')));
        $this->assertStringContainsString('writes pctest.harmless to the session', implode("\n", array_column(page_cache()->refusals(), 'reason')));
    }

    public function test_a_part_that_pushes_to_a_stack_or_does_not_reach_the_page_is_not_kept(): void
    {
        $this->assertSame('miss', $this->cache($this->get('/pc-test/pusher')));
        $this->assertSame('miss', $this->cache($this->get('/pc-test/escaped')));

        $reasons = implode("\n", array_column(page_cache()->refusals(), 'reason'));
        $this->assertStringContainsString('pushes to a stack', $reasons);
        $this->assertStringContainsString('did not reach the page as it was drawn', $reasons);
        $this->assertSame(0, (int) DB::table('page_cache')->count());
    }

    public function test_a_page_that_sets_a_cookie_or_is_not_a_200_is_not_kept(): void
    {
        $this->assertSame('miss', $this->cache($this->get('/pc-test/cookie')));
        $this->get('/pc-test/broken')->assertNotFound();
        $this->assertSame(0, (int) DB::table('page_cache')->count());
        $this->assertStringNotContainsString('pc-test/broken', implode("\n", array_column(page_cache()->refusals(), 'url')), 'a 404 is what the address is, not a reason to show');
    }

    public function test_an_undeclared_session_key_is_drawn_the_page_and_noted(): void
    {
        $response = $this->withSession(['pctest_nobody_declared' => 1])->get('/pc-test/page');
        $this->assertSame('bypass', $this->cache($response));
        $this->assertStringContainsString('session holds pctest_nobody_declared', implode("\n", array_column(page_cache()->refusals(), 'reason')));
    }

    public function test_a_write_throws_every_copy_away_once_committed_and_an_unseen_one_does_not(): void
    {
        $this->get('/pc-test/page');
        $this->get('/pc-test/tagged/1');
        $this->assertSame(2, $this->keptNow());

        // Invisible on pages: nothing goes.
        DB::table('sessions')->insert(['id' => 'pctest', 'payload' => '', 'last_activity' => time()]);
        $this->assertSame(2, $this->keptNow());

        // Rolled back: nothing it would have thrown away goes.
        DB::beginTransaction();
        DB::table('storefronts')->update(['updated_at' => now()]);
        $this->assertSame(2, $this->keptNow(), 'not before the commit');
        DB::rollBack();
        $this->assertSame(2, $this->keptNow(), 'nor after a rollback');

        // ...and it is not left behind for the next change to carry out: a
        // tag still throws away its own copies only.
        page_cache()->discard('pctest:1');
        $this->assertSame(1, $this->keptNow());

        DB::transaction(fn () => DB::table('storefronts')->update(['updated_at' => now()]));
        $this->assertSame(0, $this->keptNow());
        $this->assertStringContainsString('update storefronts', (string) (page_cache()->lastFlush()['why'] ?? ''));
    }

    public function test_a_savepoint_rolled_back_takes_back_only_what_it_threw_away(): void
    {
        $this->get('/pc-test/tagged/1');
        $this->get('/pc-test/tagged/2');

        DB::transaction(function () {
            page_cache()->discard('pctest:1');

            try {
                DB::transaction(function () {
                    page_cache()->discard('pctest:2');

                    throw new RuntimeException('changed its mind');
                });
            } catch (RuntimeException) {
            }

            $this->assertSame(2, $this->keptNow(), 'nothing before the commit');
        });

        $this->assertSame(1, $this->keptNow());
        $this->newVisitor();
        $this->assertSame('hit', $this->cache($this->get('/pc-test/tagged/2')));
    }

    public function test_a_tag_throws_away_only_the_copies_that_carry_it(): void
    {
        $this->get('/pc-test/tagged/1');
        $this->get('/pc-test/tagged/2');
        $this->assertSame(2, $this->keptNow());

        page_cache()->discard('pctest:1');

        $this->assertSame(1, $this->keptNow());
        $this->newVisitor();
        $this->assertSame('hit', $this->cache($this->get('/pc-test/tagged/2')));
        $this->assertSame('stored', $this->cache($this->get('/pc-test/tagged/1')));
    }

    public function test_each_value_of_a_vary_has_its_own_copy(): void
    {
        $this->get('/pc-test/page');

        $this->newVisitor();
        $this->assertSame('stored', $this->cache($this->withSession(['pctest.flavour' => 'sweet'])->get('/pc-test/page')));

        $this->newVisitor();
        $this->assertSame('hit', $this->cache($this->withSession(['pctest.flavour' => 'sweet'])->get('/pc-test/page')));

        $this->newVisitor();
        $this->assertSame('hit', $this->cache($this->get('/pc-test/page')));
        $this->assertSame(2, (int) DB::table('page_cache')->count());
    }

    public function test_the_address_is_its_declared_parameters_only(): void
    {
        $this->assertSame('stored', $this->cache($this->get('/pc-test/list?page=2')));
        $this->newVisitor();
        $second = $this->get('/pc-test/list?page=2');
        $second->assertSee('page 2');
        $this->assertSame('hit', $this->cache($second));
        $this->assertSame('stored', $this->cache($this->get('/pc-test/list')), 'another page of the list, another copy');

        // Anything else is not a page this copy is.
        $this->assertSame('bypass', $this->cache($this->get('/pc-test/list?colour=red')));
        $this->assertSame('bypass', $this->cache($this->get('/pc-test/list?page[]=2')));
        $this->assertSame('bypass', $this->cache($this->get('/pc-test/list?page=2&nocache=1')));
    }

    public function test_an_advert_link_is_served_the_clean_copy_but_never_copied(): void
    {
        $this->newVisitor();
        $this->assertSame('miss', $this->cache($this->get('/pc-test/page?utm_source=mail&gclid=abc')));
        $this->assertSame(0, (int) DB::table('page_cache')->count());

        $this->get('/pc-test/page');
        $this->newVisitor();
        $this->assertSame('hit', $this->cache($this->get('/pc-test/page?utm_source=mail')));
    }

    public function test_htmx_is_not_served_a_copy_and_head_is(): void
    {
        $this->assertSame('bypass', $this->cache($this->get('/pc-test/page', ['HX-Request' => 'true'])));
        $this->get('/pc-test/page');

        $this->newVisitor();
        $head = $this->call('HEAD', '/pc-test/page');
        $this->assertSame('hit', $this->cache($head));
    }

    public function test_a_page_that_changes_by_itself_is_kept_only_until_then(): void
    {
        $this->assertSame('miss', $this->cache($this->get('/pc-test/stale')), 'changed before it was drawn');

        $this->freezeSecond();
        $this->get('/pc-test/soon');
        $expires = DB::table('page_cache')->value('expires_at');
        $this->assertSame(now()->addMinutes(5)->format('Y-m-d H:i'), substr((string) $expires, 0, 16));

        // The database's clock decides what is served — inside a test's
        // transaction it stands still at its start: well past, drawn again.
        DB::table('page_cache')->update(['expires_at' => now()->subHour()]);
        $this->newVisitor();
        $this->assertSame('stored', $this->cache($this->get('/pc-test/soon')));
    }

    public function test_a_visit_action_runs_on_every_visit_served_from_a_copy_or_not(): void
    {
        $this->assertSame('stored', $this->cache($this->get('/pc-test/visited')));
        $this->assertSame(1, $this->visits);

        $this->newVisitor();
        $this->assertSame('hit', $this->cache($this->get('/pc-test/visited')));
        $this->assertSame(2, $this->visits);
    }

    public function test_switched_off_nothing_is_kept_and_switching_throws_everything_away(): void
    {
        $this->get('/pc-test/page');
        $this->assertSame(1, $this->keptNow());

        app(SettingsManager::class)->set('core', PageCache::ENABLED, false);
        $this->assertSame(0, $this->keptNow());

        $this->newVisitor();
        $this->assertNull($this->cache($this->get('/pc-test/page')));
        $this->assertSame(1, (int) DB::table('page_cache')->count(), 'nothing new is kept');
    }

    public function test_a_copy_that_cannot_be_served_is_drawn_again_and_forgotten(): void
    {
        $this->get('/pc-test/page');
        DB::table('page_cache')->update(['body' => 'not deflated at all']);

        $this->newVisitor();
        $response = $this->get('/pc-test/page');
        $response->assertOk()->assertSee('<div id="body">plain</div>', false);
        $this->assertSame('stored', $this->cache($response), 'drawn again and kept afresh');
    }

    public function test_the_cache_screen_shows_what_is_kept_and_why_not_and_throws_it_away(): void
    {
        $this->get('/pc-test/page');
        $this->get('/pc-test/leaky');

        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin)->get('http://localhost/admin/cache')
            ->assertOk()
            ->assertSee(__('Pages kept for guests'))
            ->assertSee(__('Throw the kept pages away'))
            ->assertSee('reads the session&#039;s pctest.secret outside a personal part', false)
            ->assertSee('/pc-test/leaky');

        $this->actingAs($admin)->post('http://localhost/admin/cache/clear', ['what' => 'pages'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(0, $this->keptNow());
        $this->assertSame([], page_cache()->refusals(), 'a fresh look at what is not kept');
        $this->assertSame('thrown away on Admin → Cache and sessions', page_cache()->lastFlush()['why'] ?? null);
    }

    /** Copies that would be served right now. */
    protected function keptNow(): int
    {
        return (int) DB::table('page_cache')
            ->whereRaw('generation = (SELECT last_value FROM page_cache_generation)')
            ->where('expires_at', '>', now())
            ->count();
    }
}
