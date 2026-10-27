<?php

namespace Tests\Feature\Core;

use App\Core\Modules\ModuleArchive;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

/**
 * A module arriving as a .zip is somebody else's code arriving on the server,
 * so the rules are pinned one by one: names are checked before anything is
 * written, one module per archive, only forwards, and only the built-in
 * Administrator group may do it at all.
 */
class ModuleArchiveTest extends TestCase
{
    protected string $modulesDir;

    protected string $work;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modulesDir = storage_path('framework/testing/archive-modules');
        $this->work = storage_path('framework/testing/archive-work');
        File::deleteDirectory($this->modulesDir);
        File::deleteDirectory($this->work);
        File::ensureDirectoryExists($this->modulesDir);
        File::ensureDirectoryExists($this->work);

        // The container's manager has read the real modules/ already.
        config(['torgash.modules_path' => $this->modulesDir]);
        $this->app->instance(ModuleManager::class, new ModuleManager($this->app));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->modulesDir);
        File::deleteDirectory($this->work);

        parent::tearDown();
    }

    /** @param array<string, string> $files path in the archive => content */
    protected function zip(array $files): string
    {
        $path = $this->work.'/'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    protected function manifest(string $version = '1.0.0'): string
    {
        return json_encode(['id' => 'acme.demo', 'name' => 'Demo', 'version' => $version, 'provider' => 'Acme\\Demo\\Provider']);
    }

    public function test_a_module_in_a_folder_is_put_into_modules(): void
    {
        $result = app(ModuleArchive::class)->put($this->zip([
            'acme-demo/manifest.json' => $this->manifest(),
            'acme-demo/src/Provider.php' => '<?php',
        ]));

        $this->assertSame('acme.demo', $result['id']);
        $this->assertFileExists($this->modulesDir.'/acme.demo/manifest.json');
        $this->assertFileExists($this->modulesDir.'/acme.demo/src/Provider.php');
        $this->assertNotNull(app(ModuleManager::class)->find('acme.demo'), 'the screen sees it at once');
    }

    public function test_a_path_that_climbs_out_refuses_the_whole_archive(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            app(ModuleArchive::class)->put($this->zip([
                'manifest.json' => $this->manifest(),
                '../../public/shell.php' => '<?php system($_GET["c"]);',
            ]));
        } finally {
            $this->assertFileDoesNotExist(base_path('public/shell.php'));
            $this->assertDirectoryDoesNotExist($this->modulesDir.'/acme.demo');
        }
    }

    public function test_two_modules_in_one_archive_are_refused(): void
    {
        $this->expectException(RuntimeException::class);

        app(ModuleArchive::class)->put($this->zip([
            'a/manifest.json' => $this->manifest(),
            'b/manifest.json' => $this->manifest(),
        ]));
    }

    public function test_only_a_newer_version_replaces_one_already_here(): void
    {
        app(ModuleArchive::class)->put($this->zip(['manifest.json' => $this->manifest('1.0.0')]));

        try {
            app(ModuleArchive::class)->put($this->zip(['manifest.json' => $this->manifest('1.0.0')]));
            $this->fail('The same version must not replace itself.');
        } catch (RuntimeException) {
        }

        $result = app(ModuleArchive::class)->put($this->zip(['manifest.json' => $this->manifest('1.1.0')]));

        $this->assertSame('1.0.0', $result['replaced']);
        $this->assertSame('1.1.0', app(ModuleManager::class)->find('acme.demo')->version);
        $this->assertNotEmpty(glob(storage_path('app/module-backups/acme.demo-1.0.0-*')), 'the old folder is kept');
        File::deleteDirectory(storage_path('app/module-backups'));
    }

    /** @return array<string, string> two migrations, the second of which fails halfway */
    protected function failingMigrations(string $prefix = ''): array
    {
        $create = fn (string $table) => "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\Schema;\n"
            ."return new class extends Migration {\n    public function up(): void { Schema::create('{$table}', fn (\$t) => \$t->id()); }\n"
            ."    public function down(): void { Schema::dropIfExists('{$table}'); }\n};\n";

        return [
            $prefix.'migrations/2026_10_17_000001_create_acme_demo_first_table.php' => $create('m_acme_demo_first'),
            $prefix.'migrations/2026_10_17_000002_create_acme_demo_second_table.php' => str_replace(
                "fn (\$t) => \$t->id()); }", "fn (\$t) => \$t->id()); throw new \\RuntimeException('second migration broke'); }",
                $create('m_acme_demo_second'),
            ),
        ];
    }

    /**
     * A new version whose second migration fails leaves the schema as the old
     * version knew it — not with the first migration applied under the old
     * folder that was put back.
     */
    public function test_a_replacement_whose_migrations_fail_leaves_none_of_them_applied(): void
    {
        app(ModuleArchive::class)->put($this->zip(['manifest.json' => $this->manifest('1.0.0')]));
        app(ModuleManager::class)->install('acme.demo');

        try {
            app(ModuleArchive::class)->put($this->zip(['manifest.json' => $this->manifest('1.1.0')] + $this->failingMigrations()));
            $this->fail('The broken migration must stop the replacement.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('second migration broke', $e->getMessage());
        }

        $this->assertFalse(Schema::hasTable('m_acme_demo_first'), 'the first migration went back with the second');
        $this->assertFalse(DB::table('migrations')->where('migration', '2026_10_17_000001_create_acme_demo_first_table')->exists());
        $this->assertSame('1.0.0', app(ModuleManager::class)->find('acme.demo')->version, 'the old folder is back');
        $this->assertSame('1.0.0', DB::table('modules')->where('module_id', 'acme.demo')->value('version'));
        File::deleteDirectory(storage_path('app/module-backups'));
    }

    /** The same for a first install: a failed one can simply be tried again. */
    public function test_an_install_whose_migrations_fail_can_be_tried_again(): void
    {
        app(ModuleArchive::class)->put($this->zip(['manifest.json' => $this->manifest('1.0.0')] + $this->failingMigrations()));

        try {
            app(ModuleManager::class)->install('acme.demo');
            $this->fail('The broken migration must stop the install.');
        } catch (RuntimeException) {
        }

        $this->assertFalse(Schema::hasTable('m_acme_demo_first'));
        $this->assertFalse(app(ModuleManager::class)->isInstalled('acme.demo'));

        // Without the broken file it installs: nothing of the failed attempt
        // is in the way. (Deleted, not mended: Laravel's migrator keeps a
        // file it has required for the rest of the process.)
        File::delete($this->modulesDir.'/acme.demo/migrations/2026_10_17_000002_create_acme_demo_second_table.php');
        app(ModuleManager::class)->install('acme.demo');

        $this->assertTrue(Schema::hasTable('m_acme_demo_first'));
        $this->assertTrue(app(ModuleManager::class)->isInstalled('acme.demo'));
    }

    /** Uploading PHP is more than any permission the shop can hand out. */
    public function test_only_the_built_in_group_may_upload(): void
    {
        $role = Role::findOrCreate('modules-only');
        $role->syncPermissions(['admin.access', 'modules.manage']);
        $manager = User::create(['name' => 'M', 'email' => 'm@torgash.local', 'password' => 'secret123']);
        $manager->assignRole($role);

        $upload = UploadedFile::fake()->createWithContent('demo.zip', (string) file_get_contents($this->zip(['manifest.json' => $this->manifest()])));

        $this->actingAs($manager)
            ->post('http://localhost/admin/modules/upload', ['archive' => $upload])
            ->assertForbidden();

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/modules/upload', ['archive' => $upload])
            ->assertRedirect();

        $this->assertFileExists($this->modulesDir.'/acme.demo/manifest.json');
    }

    /** Uninstalling drops a module's tables: not something every member of the staff may do. */
    public function test_switching_modules_needs_its_own_permission(): void
    {
        $role = Role::findOrCreate('orders-only');
        $role->syncPermissions(['admin.access']);
        $clerk = User::create(['name' => 'C', 'email' => 'c@torgash.local', 'password' => 'secret123']);
        $clerk->assignRole($role);

        $this->actingAs($clerk)
            ->post('http://localhost/admin/modules/torgash.hello/toggle')
            ->assertForbidden();
    }
}
