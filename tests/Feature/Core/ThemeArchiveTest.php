<?php

namespace Tests\Feature\Core;

use App\Core\Themes\ThemeArchive;
use App\Core\Themes\ThemeManager;
use App\Models\Storefront;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

/**
 * A theme arriving as a .zip is code arriving on the server, and a theme
 * taken away is a storefront with nothing to draw with — so the rules are
 * pinned one by one: the folder is the archive's, an author is required (or
 * the next update eats the theme), the engine's own themes are neither
 * uploaded nor deleted, nothing worn or based on goes, only forwards, and
 * only the built-in Administrator group may do any of it.
 */
class ThemeArchiveTest extends TestCase
{
    protected string $themesDir;

    protected string $work;

    protected function setUp(): void
    {
        parent::setUp();

        $this->themesDir = storage_path('framework/testing/archive-themes');
        $this->work = storage_path('framework/testing/archive-themes-work');
        File::deleteDirectory($this->themesDir);
        File::deleteDirectory($this->work);
        File::ensureDirectoryExists($this->work);

        // The engine's theme, as the distribution ships it: no author.
        File::ensureDirectoryExists($this->themesDir.'/default/views');
        file_put_contents($this->themesDir.'/default/theme.json', json_encode(['name' => 'Torgash Default', 'parent' => null, 'version' => '1.0.0']));

        config(['torgash.themes_path' => $this->themesDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->themesDir);
        File::deleteDirectory($this->work);
        File::deleteDirectory(storage_path('app/theme-backups'));

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

    protected function meta(array $overrides = []): string
    {
        return json_encode($overrides + ['name' => 'Acme Shop', 'parent' => 'default', 'version' => '1.0.0', 'author' => ['name' => 'Acme']]);
    }

    protected function archive(): ThemeArchive
    {
        return app(ThemeArchive::class);
    }

    public function test_a_theme_in_a_folder_is_put_into_themes_under_that_folder(): void
    {
        $result = $this->archive()->put($this->zip([
            'acme-shop/theme.json' => $this->meta(),
            'acme-shop/views/layouts/storefront.blade.php' => 'ACME',
        ]));

        $this->assertSame('acme-shop', $result['dir']);
        $this->assertNull($result['replaced']);
        $this->assertFileExists($this->themesDir.'/acme-shop/views/layouts/storefront.blade.php');
        $this->assertArrayHasKey('acme-shop', app(ThemeManager::class)->available(), 'the screen sees it at once');
    }

    public function test_a_theme_zipped_from_inside_its_folder_is_named_after_itself(): void
    {
        $result = $this->archive()->put($this->zip(['theme.json' => $this->meta(['name' => 'Acme Shop'])]));

        $this->assertSame('acme-shop', $result['dir']);
        $this->assertFileExists($this->themesDir.'/acme-shop/theme.json');
    }

    public function test_a_theme_without_an_author_of_its_own_is_refused(): void
    {
        foreach ([['author' => null], ['author' => ['name' => 'Torgash']]] as $author) {
            try {
                $this->archive()->put($this->zip(['acme/theme.json' => $this->meta($author)]));
                $this->fail('A theme the next update would eat must be refused.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('author', $e->getMessage());
            }
        }

        $this->assertDirectoryDoesNotExist($this->themesDir.'/acme');
    }

    public function test_the_engines_own_theme_cannot_be_uploaded_over(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ships with the engine');

        try {
            $this->archive()->put($this->zip(['default/theme.json' => $this->meta(['name' => 'Fake default', 'parent' => null, 'version' => '9.0.0'])]));
        } finally {
            $this->assertSame('Torgash Default', app(ThemeManager::class)->meta('default')['name'], 'untouched');
        }
    }

    public function test_a_theme_based_on_one_that_is_not_here_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('based on');

        $this->archive()->put($this->zip(['acme/theme.json' => $this->meta(['parent' => 'nowhere'])]));
    }

    public function test_a_path_that_climbs_out_refuses_the_whole_archive(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            $this->archive()->put($this->zip([
                'acme/theme.json' => $this->meta(),
                'acme/../../public/shell.php' => '<?php system($_GET["c"]);',
            ]));
        } finally {
            $this->assertFileDoesNotExist(base_path('public/shell.php'));
            $this->assertDirectoryDoesNotExist($this->themesDir.'/acme');
        }
    }

    public function test_only_a_newer_version_replaces_one_already_here(): void
    {
        $this->archive()->put($this->zip(['acme/theme.json' => $this->meta(['version' => '1.0.0']), 'acme/old.txt' => 'old']));

        try {
            $this->archive()->put($this->zip(['acme/theme.json' => $this->meta(['version' => '1.0.0'])]));
            $this->fail('The same version must not replace itself.');
        } catch (RuntimeException) {
        }

        $result = $this->archive()->put($this->zip(['acme/theme.json' => $this->meta(['version' => '1.1.0']), 'acme/new.txt' => 'new']));

        $this->assertSame('1.0.0', $result['replaced']);
        $this->assertSame('1.1.0', app(ThemeManager::class)->meta('acme')['version']);
        $this->assertFileDoesNotExist($this->themesDir.'/acme/old.txt', 'replaced whole, not merged');
        $this->assertNotEmpty(glob(storage_path('app/theme-backups/acme-1.0.0-*')), 'the old folder is kept');
    }

    public function test_the_engines_own_theme_cannot_be_deleted(): void
    {
        $this->assertStringContainsString('ships with the engine', $this->archive()->undeletable('default'));

        try {
            $this->archive()->remove('default');
            $this->fail('default must stay.');
        } catch (RuntimeException) {
        }

        $this->assertFileExists($this->themesDir.'/default/theme.json');
    }

    public function test_a_theme_worn_or_based_on_cannot_be_deleted(): void
    {
        $this->archive()->put($this->zip(['acme/theme.json' => $this->meta()]));
        $this->archive()->put($this->zip(['acme-child/theme.json' => $this->meta(['name' => 'Acme child', 'parent' => 'acme'])]));

        $this->assertStringContainsString('based on', $this->archive()->undeletable('acme'));

        $storefront = Storefront::query()->firstOrFail();
        $storefront->forceFill(['theme' => 'acme-child'])->save();
        $this->assertStringContainsString($storefront->name, $this->archive()->undeletable('acme-child'));

        $storefront->forceFill(['theme' => 'default'])->save();
        $this->assertNull($this->archive()->undeletable('acme-child'));

        $this->archive()->remove('acme-child');
        $this->assertDirectoryDoesNotExist($this->themesDir.'/acme-child');
        $this->assertNotEmpty(glob(storage_path('app/theme-backups/acme-child-1.0.0-*')), 'moved aside, not destroyed');

        // With its child gone, the parent may go too.
        $this->assertNull($this->archive()->undeletable('acme'));
    }

    /** Uploading or deleting code is more than any permission the shop can hand out. */
    public function test_only_the_built_in_group_may_upload_or_delete(): void
    {
        $role = Role::findOrCreate('themes-only');
        $role->syncPermissions(['admin.access', 'themes.manage']);
        $manager = User::create(['name' => 'T', 'email' => 't@torgash.local', 'password' => 'secret123']);
        $manager->assignRole($role);

        $upload = UploadedFile::fake()->createWithContent('acme.zip', (string) file_get_contents($this->zip(['acme/theme.json' => $this->meta()])));

        $this->actingAs($manager)
            ->post('http://localhost/admin/themes/upload', ['archive' => $upload])
            ->assertForbidden();
        $this->actingAs($manager)->get('http://localhost/admin/themes')
            ->assertOk()->assertDontSee('modal-theme-upload');

        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin)
            ->post('http://localhost/admin/themes/upload', ['archive' => $upload])
            ->assertRedirect(route('admin.themes.index'));
        $this->assertFileExists($this->themesDir.'/acme/theme.json');

        $screen = $this->actingAs($admin)->get('http://localhost/admin/themes')->assertOk();
        $screen->assertSee('modal-theme-upload');
        $screen->assertSee(route('admin.themes.destroy', 'acme'), false);
        $screen->assertDontSee(route('admin.themes.destroy', 'default'), false);

        $this->actingAs($manager)->delete('http://localhost/admin/themes/acme')->assertForbidden();
        $this->assertFileExists($this->themesDir.'/acme/theme.json');

        $this->actingAs($admin)->delete('http://localhost/admin/themes/default')->assertSessionHasErrors('theme');
        $this->assertFileExists($this->themesDir.'/default/theme.json');

        $this->actingAs($admin)->delete('http://localhost/admin/themes/acme')->assertRedirect(route('admin.themes.index'));
        $this->assertDirectoryDoesNotExist($this->themesDir.'/acme');
    }
}
