<?php

namespace Tests\Feature\Core;

use App\Core\Assets\StyleBuilder;
use App\Core\Modules\ModuleManager;
use App\Core\Tasks\OrphanJobs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * Code changing under a running shop (PLAN §45.4–6): a module that does not
 * fit this core is refused, or held back without taking the shop down; the
 * caches, workers and stylesheet are refreshed after every change; and jobs
 * whose code is gone are taken away instead of failing forever.
 */
class CodeChangesTest extends TestCase
{
    protected function fixtures(): ModuleManager
    {
        config(['torgash.modules_path' => base_path('tests/fixtures/modules')]);

        return new ModuleManager($this->app);
    }

    public function test_a_module_for_another_core_is_refused_at_install_and_at_enable(): void
    {
        $modules = $this->fixtures();
        config(['torgash.version' => '0.0.5']);

        try {
            $modules->install('torgash.fixture');
            $this->fail('A module wanting core >=0.1 installed on 0.0.5.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('>=0.1', $e->getMessage());
        }
        $this->assertFalse($modules->isInstalled('torgash.fixture'));

        config(['torgash.version' => '0.1.0']);
        $modules->install('torgash.fixture');
        $modules->setEnabled('torgash.fixture', false);

        config(['torgash.version' => '0.0.5']);
        $this->expectException(RuntimeException::class);
        $modules->setEnabled('torgash.fixture', true);
    }

    public function test_a_module_that_stops_fitting_is_held_back_with_everything_that_needs_it(): void
    {
        $dir = storage_path('framework/testing/modules-'.bin2hex(random_bytes(4)));
        $manifest = fn (string $id, string $core, array $needs = []) => File::put("$dir/$id/manifest.json", json_encode([
            // A class that exists: a provider missing from its files is a case of its own (below).
            'id' => $id, 'name' => $id, 'version' => '1.0.0', 'provider' => \Illuminate\Support\ServiceProvider::class,
            'requires' => ['core' => $core, 'modules' => $needs],
        ]));
        foreach (['t.base', 't.child', 't.free'] as $id) {
            File::ensureDirectoryExists("$dir/$id");
        }
        $manifest('t.base', '>=0.5');
        $manifest('t.child', '>=0.1', ['t.base']);
        $manifest('t.free', '>=0.1');

        try {
            config(['torgash.modules_path' => $dir, 'torgash.version' => '0.4.0']);
            foreach (['t.base', 't.child', 't.free'] as $id) {
                DB::table('modules')->insert(['module_id' => $id, 'version' => '1.0.0', 'enabled' => true, 'installed_at' => now()]);
            }
            $modules = new ModuleManager($this->app);

            // Only this test's modules: the real ones installed in the test database have no folder here.
            $this->assertSame(['t.base' => ['core', '>=0.5'], 't.child' => ['module', 't.base']],
                array_filter($modules->blocked(), fn ($id) => str_starts_with($id, 't.'), ARRAY_FILTER_USE_KEY));
            $this->assertFalse($modules->isEnabled('t.base'));
            $this->assertFalse($modules->isEnabled('t.child'));
            $this->assertTrue($modules->isEnabled('t.free'));
            $this->assertSame(['t.free'], array_keys($modules->enabled()));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_a_folder_that_cannot_be_read_is_left_out_and_the_shop_runs_on(): void
    {
        $dir = storage_path('framework/testing/modules-'.bin2hex(random_bytes(4)));
        $write = function (string $folder, string $json) use ($dir) {
            File::ensureDirectoryExists("$dir/$folder");
            File::put("$dir/$folder/manifest.json", $json);
        };
        $module = fn (string $id, string $provider, array $needs = []) => json_encode([
            'id' => $id, 'name' => $id, 'version' => '1.0.0', 'provider' => $provider,
            'requires' => ['core' => '>=0.1', 'modules' => $needs],
        ]);

        // Not JSON: one backslash where JSON wants two — the mistake that took the dev shop down.
        $write('t.typo', '{"id": "t.typo", "provider": "A\B"}');
        $write('t.noid', '{"name": "No id"}');
        $write('t.noclass', $module('t.noclass', 'Nowhere\\Provider'));
        $write('t.needs', $module('t.needs', \Illuminate\Support\ServiceProvider::class, ['t.typo']));
        $write('t.fine', $module('t.fine', \Illuminate\Support\ServiceProvider::class));

        try {
            config(['torgash.modules_path' => $dir]);
            foreach (['t.typo', 't.noclass', 't.needs', 't.fine', 't.gone'] as $id) {
                DB::table('modules')->insert(['module_id' => $id, 'version' => '1.0.0', 'enabled' => true, 'installed_at' => now()]);
            }
            $modules = new ModuleManager($this->app);

            // Read without a fatal error; the two unreadable folders are named, with why.
            $this->assertSame(['t.fine', 't.needs', 't.noclass'], collect($modules->all())->keys()->sort()->values()->all());
            $this->assertStringContainsString('not valid JSON', $modules->broken()['t.typo']);
            $this->assertStringContainsString('"id"', $modules->broken()['t.noid']);

            // Held back: a provider its files do not have, a module it needs that is unreadable, one whose folder is gone.
            $this->assertSame(['provider', 'Nowhere\\Provider'], $modules->blocked()['t.noclass']);
            $this->assertSame(['module', 't.typo'], $modules->blocked()['t.needs']);
            $this->assertSame(['missing', 't.gone'], $modules->blocked()['t.gone']);
            $this->assertFalse($modules->isEnabled('t.gone'));
            $this->assertSame(['t.fine'], array_keys($modules->enabled()));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_every_change_of_modules_refreshes_the_code_caches_and_the_stylesheet(): void
    {
        $builds = 0;
        $this->app->instance(StyleBuilder::class, new class($builds) extends StyleBuilder
        {
            public function __construct(public int &$count) {}

            public function build(): array
            {
                $this->count++;

                return ['bytes' => 1, 'seconds' => 0.0, 'builder' => 'fake'];
            }
        });
        config(['torgash.refresh_on_change' => true]);
        $modules = $this->fixtures();

        $modules->install('torgash.fixture');
        $modules->setEnabled('torgash.fixture', false);
        $modules->setEnabled('torgash.fixture', true);
        $modules->uninstall('torgash.fixture');

        $this->assertSame(4, $builds);
    }

    public function test_an_uninstalled_module_takes_its_waiting_jobs_with_it(): void
    {
        $modules = $this->fixtures();
        $modules->install('torgash.fixture');
        $job = fn (string $class) => DB::table('jobs')->insertGetId([
            'queue' => 'default', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
            'payload' => json_encode(['displayName' => $class, 'data' => []]),
        ]);
        $its = $job('Tests\\Fixtures\\SendSomething');
        $others = $job('App\\Core\\Assets\\BuildStyles');

        $modules->uninstall('torgash.fixture');

        $this->assertFalse(DB::table('jobs')->where('id', $its)->exists());
        $this->assertTrue(DB::table('jobs')->where('id', $others)->exists());
    }

    public function test_failed_jobs_without_code_are_cleared_and_the_rest_kept(): void
    {
        $failed = fn (string $class) => DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => $class]), 'exception' => 'x', 'failed_at' => now(),
        ]);
        $gone = $failed('Torgash\\Gone\\Mail\\Letter');
        $kept = $failed('App\\Core\\Assets\\BuildStyles');

        $this->assertContains($gone, app(OrphanJobs::class)->missingFailed());
        $this->artisan('torgash:jobs-prune-missing')->assertSuccessful();

        $this->assertFalse(DB::table('failed_jobs')->where('id', $gone)->exists());
        $this->assertTrue(DB::table('failed_jobs')->where('id', $kept)->exists());
    }
}
