<?php

namespace Tests\Feature\Speed;

use App\Core\Hooks\HookBus;
use App\Core\Hooks\HookObserver;
use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Torgash\Speed\Attribution;
use Torgash\Speed\Meter;
use Torgash\Speed\Profile;
use Torgash\Speed\Prune;
use Torgash\Speed\Recorder;
use Torgash\Speed\Reports;
use Torgash\Speed\Sql;

/**
 * Measuring the shop: real requests go through the real pipeline, and what
 * lands in the aggregates is checked against what the request did.
 */
class SpeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['torgash.speed.measure_in_tests' => true]);
        $this->set('enabled', true);
        $this->set('sample_percent', 100);
        $this->set('repeat_threshold', 5);
        $this->set('trace_threshold_ms', 100000);

        @unlink(Meter::requestedFile());

        // A page that asks one row at a time, six times — the loop this module
        // exists to find — and fires a hook on the way.
        Route::get('/speed-probe', function () {
            foreach (range(1, 6) as $id) {
                DB::select('select ? as id', [$id]);
            }

            hook_filter('speed.probe', 0, []);

            return 'ok';
        })->name('speed.probe');
        app('router')->getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        @unlink(Meter::requestedFile());

        parent::tearDown();
    }

    // -------------------------------------------------------------- recording

    public function test_a_measured_request_lands_in_the_hour_with_its_parts_adding_up(): void
    {
        $this->get('http://localhost/speed-probe')->assertOk();

        $row = DB::table('m_speed_routes')->where('route', 'speed.probe')->first();

        $this->assertNotNull($row, 'the request was measured');
        $this->assertSame(1, (int) $row->requests);
        $this->assertSame('storefront', $row->zone);
        $this->assertGreaterThanOrEqual(6, (int) $row->queries);
        $this->assertSame(1, array_sum(array_map(fn ($i) => (int) $row->{'h'.$i}, range(0, 7))));
        // The parts never claim more than the whole.
        $this->assertLessThanOrEqual((float) $row->total_ms + 0.01, $row->boot_ms + $row->db_ms + $row->http_ms + $row->hooks_ms);
    }

    public function test_nothing_is_measured_when_switched_off_or_not_sampled(): void
    {
        $this->set('sample_percent', 0);
        $this->get('http://localhost/speed-probe')->assertOk();

        $this->set('sample_percent', 100);
        $this->set('enabled', false);
        $this->get('http://localhost/speed-probe')->assertOk();

        $this->assertSame(0, DB::table('m_speed_routes')->count());
    }

    /** The loop is found, counted per page, and tied to the page it ran on. */
    public function test_a_query_repeated_on_one_page_is_recorded_as_a_loop(): void
    {
        $this->get('http://localhost/speed-probe')->assertOk();
        $this->get('http://localhost/speed-probe')->assertOk();

        $row = DB::table('m_speed_queries')->where('route', 'speed.probe')->where('sql', 'select ? as id')->first();

        $this->assertNotNull($row);
        $this->assertSame(6, (int) $row->max_repeat);
        $this->assertSame(12, (int) $row->executions);
        $this->assertSame(2, (int) $row->requests);

        $repeated = app(Reports::class)->repeated(now()->subDay(), 5);
        $this->assertContains('speed.probe', $repeated->pluck('route')->all());
    }

    public function test_tracing_my_pages_keeps_a_timeline_of_every_one_of_them(): void
    {
        $this->set('sample_percent', 0);
        app(Meter::class)->request('127.0.0.1');

        $this->get('http://localhost/speed-probe')->assertOk();

        $trace = DB::table('m_speed_traces')->where('route', 'speed.probe')->first();
        $this->assertNotNull($trace, 'a traced request is measured whatever the sample says');
        $this->assertTrue((bool) $trace->requested);

        $spans = json_decode($trace->spans, true);
        $this->assertGreaterThanOrEqual(6, collect($spans)->where(0, 'query')->count());

        $summary = json_decode($trace->summary, true);
        $this->assertSame(6, $summary['repeated'][0]['count']);

        app(Meter::class)->stopRequest('127.0.0.1');
        $this->assertFileDoesNotExist(Meter::requestedFile(), 'with nothing traced there is no file to read');
    }

    public function test_the_modules_own_writes_are_not_measured(): void
    {
        $this->get('http://localhost/speed-probe')->assertOk();

        $this->assertSame(0, DB::table('m_speed_queries')->where('sql', 'like', '%m_speed_%')->count());
    }

    // ------------------------------------------------------------------ hooks

    /** A listener's OWN time: what a hook it fired in turn costs is not its. */
    public function test_nested_hooks_are_charged_exclusively(): void
    {
        $profile = new Profile(hrtime(true), false, new Attribution, 5);
        $outer = fn () => null;
        $inner = fn () => null;

        $profile->enter('outer', $outer);
        usleep(20_000);
        $profile->enter('inner', $inner);
        usleep(30_000);
        $profile->leave('inner', $inner);
        $profile->leave('outer', $outer);

        $own = $profile->modules[(new Attribution)->of($outer)]['ms'];
        // Both closures live in this file, so they share one module; the sum
        // of own times equals the outer wall time, not outer + inner.
        $this->assertGreaterThan(45, $own);
        $this->assertLessThan(120, $own, 'the inner 30 ms is counted once, not twice');
    }

    public function test_the_bus_tells_its_observer_even_when_a_listener_throws(): void
    {
        $bus = new HookBus;
        $calls = [];
        $bus->observe(new class($calls) implements HookObserver
        {
            public function __construct(public array &$calls) {}

            public function enter(string $hook, callable $listener): void
            {
                $this->calls[] = 'enter:'.$hook;
            }

            public function leave(string $hook, callable $listener): void
            {
                $this->calls[] = 'leave:'.$hook;
            }
        });
        $bus->listen('boom', fn () => throw new \RuntimeException('x'));

        try {
            $bus->action('boom');
        } catch (\RuntimeException) {
        }

        $this->assertSame(['enter:boom', 'leave:boom'], $calls);
    }

    public function test_code_is_attributed_to_the_module_whose_file_it_is(): void
    {
        $attribution = new Attribution;

        $this->assertSame('torgash.catalog', $attribution->forFile(base_path('modules/torgash.catalog/src/CatalogServiceProvider.php')));
        $this->assertSame('core', $attribution->forFile(app_path('Core/Hooks/HookBus.php')));
        $this->assertSame('vendor', $attribution->forFile(base_path('vendor/laravel/framework/src/x.php')));
        $this->assertSame('torgash.catalog', $attribution->ofAction(\Torgash\Catalog\Http\StorefrontController::class.'@index'));
    }

    // ------------------------------------------------------------- arithmetic

    public function test_query_shapes_ignore_the_values_and_the_length_of_a_list(): void
    {
        $this->assertSame(
            Sql::shape('select * from t where id in (?, ?, ?) and name = \'x\' limit 10'),
            Sql::shape('select * from t where id in (?, ?)   and name = \'yy\' limit 20'),
        );
        $this->assertStringContainsString('m_speed_routes', Sql::shape('select h0 from m_speed_routes'));
    }

    public function test_percentiles_are_read_off_the_histogram(): void
    {
        // 10 requests under 50 ms, 10 between 400 and 800.
        $h = [10, 0, 0, 0, 10, 0, 0, 0];

        $this->assertEqualsWithDelta(50.0, Reports::percentile($h, 0.5, 700), 0.01);
        // Inside 400-800, but nothing was slower than 700: the bucket is read
        // as 400-700, so the 19th of 20 lands at 400 + 300 x 0.9.
        $this->assertEqualsWithDelta(670.0, Reports::percentile($h, 0.95, 700), 0.01);
        $this->assertSame(4, Recorder::bucket(450));
        $this->assertSame(7, Recorder::bucket(9000));
    }

    // ---------------------------------------------------------------- screens

    public function test_the_screens_render_from_real_measurements(): void
    {
        app(Meter::class)->request('127.0.0.1');
        $this->get('http://localhost/speed-probe')->assertOk();
        app(Meter::class)->stopRequest('127.0.0.1');

        $admin = User::role('admin')->firstOrFail();
        $trace = DB::table('m_speed_traces')->value('id');

        $this->actingAs($admin)->get('http://localhost/admin/speed')->assertOk()->assertSee('speed.probe')->assertSee('select ? as id');
        $this->actingAs($admin)->get('http://localhost/admin/speed/page?r=speed.probe')->assertOk()->assertSee('speed.probe');
        $this->actingAs($admin)->get('http://localhost/admin/speed/trace/'.$trace)->assertOk()->assertSee('/speed-probe');
        $this->actingAs($admin)->get('http://localhost/admin')->assertOk()->assertSee(route('admin.speed.index'));
    }

    public function test_the_screens_are_closed_without_the_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('http://localhost/admin/speed')->assertForbidden();
    }

    public function test_old_measurements_are_pruned(): void
    {
        $this->get('http://localhost/speed-probe')->assertOk();
        DB::table('m_speed_routes')->update(['bucket_at' => now()->subDays(40)]);

        app(Prune::class)->run();

        $this->assertSame(0, DB::table('m_speed_routes')->count());
    }

    protected function set(string $key, mixed $value): void
    {
        app(SettingsManager::class)->set('torgash.speed', $key, $value);
    }
}
