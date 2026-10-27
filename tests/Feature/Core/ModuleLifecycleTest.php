<?php

namespace Tests\Feature\Core;

use App\Core\Modules\ModuleManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The engine's headline promise: a module installs and uninstalls without
 * leaving anything behind. Every session so far has checked this by hand.
 *
 * The modules used here live under tests/fixtures/modules and exist only for
 * this file, so the suite never has to install or remove a real one.
 */
class ModuleLifecycleTest extends TestCase
{
    protected ModuleManager $modules;

    protected function setUp(): void
    {
        parent::setUp();

        config(['torgash.modules_path' => base_path('tests/fixtures/modules')]);

        // A fresh instance: the container's own has already cached the real
        // modules directory from the boot that happened before this test.
        $this->modules = new ModuleManager($this->app);
    }

    public function test_install_creates_the_table_permission_and_registration(): void
    {
        $this->modules->install('torgash.fixture');

        $this->assertTrue(Schema::hasTable('m_fixture_items'));
        $this->assertTrue(Permission::where('name', 'fixture.manage')->exists());
        $this->assertTrue($this->modules->isInstalled('torgash.fixture'));
        $this->assertTrue($this->modules->isEnabled('torgash.fixture'));
    }

    public function test_uninstall_leaves_no_traces(): void
    {
        $this->modules->install('torgash.fixture');

        // Settings written while it was installed must go with it.
        DB::table('settings')->insert([
            'module' => 'torgash.fixture',
            'key' => 'greeting',
            'value' => json_encode('changed by the admin'),
            'storefront_id' => null,
        ]);

        $this->modules->uninstall('torgash.fixture');

        $this->assertFalse(Schema::hasTable('m_fixture_items'), 'the module table survived uninstall');
        $this->assertFalse(Permission::where('name', 'fixture.manage')->exists());
        $this->assertSame(0, DB::table('settings')->where('module', 'torgash.fixture')->count());
        $this->assertSame(0, DB::table('modules')->where('module_id', 'torgash.fixture')->count());
        $this->assertSame(
            0,
            DB::table('migrations')->where('migration', '2026_01_01_000000_create_fixture_items_table')->count(),
            'the migration record survived uninstall, so reinstalling would skip the table',
        );
    }

    public function test_a_module_can_be_installed_again_after_being_removed(): void
    {
        $this->modules->install('torgash.fixture');
        $this->modules->uninstall('torgash.fixture');
        $this->modules->install('torgash.fixture');

        $this->assertTrue(Schema::hasTable('m_fixture_items'));
    }

    /**
     * Laravel identifies a migration by filename alone. Two modules shipping
     * the same filename are one migration to it: the second install would skip
     * its own file and end up with no table, and uninstalling either would run
     * the other's down(). Installation has to refuse.
     */
    public function test_installing_a_module_whose_migration_filename_is_taken_is_refused(): void
    {
        $this->modules->install('torgash.fixture');

        try {
            $this->modules->install('torgash.clash');
            $this->fail('a colliding migration filename was accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('2026_01_01_000000_create_fixture_items_table', $e->getMessage());
        }

        $this->assertFalse($this->modules->isInstalled('torgash.clash'));
        $this->assertFalse(Schema::hasTable('m_clash_items'));
    }

    public function test_installing_the_same_module_twice_is_refused(): void
    {
        $this->modules->install('torgash.fixture');

        $this->expectException(RuntimeException::class);
        $this->modules->install('torgash.fixture');
    }

    /**
     * Migrations undo what is in the database; a module that has to touch
     * anything else says so by implementing ModuleLifecycle, and the promise is
     * the same — installing does it, uninstalling undoes it. This is what puts
     * `/1c_exchange.php` in public/ and takes it away again.
     */
    public function test_a_module_can_do_its_own_work_on_install_and_undo_it_on_uninstall(): void
    {
        $marker = \Tests\Fixtures\FixtureServiceProvider::marker();
        @unlink($marker);

        $this->modules->install('torgash.fixture');
        $this->assertFileExists($marker, 'the module’s own install step did not run');

        $this->modules->uninstall('torgash.fixture');
        $this->assertFileDoesNotExist($marker, 'the module left its own traces behind');
    }

    public function test_settings_default_comes_from_the_manifest_of_an_installed_module(): void
    {
        $this->modules->install('torgash.fixture');

        // Defaults are registered from manifests at boot; register this one by
        // hand because the fixture was not installed when the app booted.
        app(\App\Core\Settings\SettingsManager::class)->registerDefaults('torgash.fixture', ['greeting' => 'hello from the manifest']);

        $this->assertSame('hello from the manifest', setting('torgash.fixture', 'greeting'));
    }
}
