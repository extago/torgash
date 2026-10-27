<?php

namespace Tests\Feature\Core;

use App\Core\Admin\AdminMenu;
use App\Core\Modules\ModuleManifest;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * How a module is recognised on the modules screen — its icon, its own
 * picture, its tint and its section — all from a manifest somebody else
 * wrote, so each is checked rather than trusted.
 */
class ModuleLookTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/module-look');
        File::deleteDirectory($this->dir);
        File::ensureDirectoryExists($this->dir.'/assets');
        File::put($this->dir.'/assets/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        File::put($this->dir.'/secret.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        File::put($this->dir.'/assets/run.php', '<?php echo 1;');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** @param array<string, mixed> $data */
    protected function manifest(array $data): ModuleManifest
    {
        File::put($this->dir.'/manifest.json', json_encode($data + ['id' => 'acme.look', 'provider' => 'X']));

        return ModuleManifest::fromFile($this->dir.'/manifest.json');
    }

    public function test_the_icon_is_the_manifests_own_else_its_first_menu_items(): void
    {
        $this->assertSame('rocket', $this->manifest(['icon' => 'rocket'])->icon());
        $this->assertSame('truck', $this->manifest(['admin_menu' => [['icon' => 'truck', 'group' => 'logistics']]])->icon());
        // A typo does not take the screen down: it falls through.
        $this->assertSame('blocks', $this->manifest(['icon' => 'no-such-icon-anywhere'])->icon());
    }

    public function test_a_logo_is_only_a_picture_inside_the_modules_assets(): void
    {
        $this->assertSame(realpath($this->dir.'/assets/logo.svg'), $this->manifest(['logo' => 'assets/logo.svg'])->logoFile());
        $this->assertNull($this->manifest(['logo' => 'secret.svg'])->logoFile(), 'outside assets/');
        $this->assertNull($this->manifest(['logo' => 'assets/../secret.svg'])->logoFile(), 'climbing out');
        $this->assertNull($this->manifest(['logo' => 'assets/run.php'])->logoFile(), 'not a picture');
    }

    public function test_a_colour_is_a_hex_or_nothing(): void
    {
        $this->assertSame('#635bff', $this->manifest(['color' => '#635bff'])->color());
        $this->assertNull($this->manifest(['color' => 'red;background:url(x)'])->color());
    }

    public function test_a_module_without_a_menu_says_where_it_belongs(): void
    {
        $menu = app(AdminMenu::class);

        $this->assertSame('sales', $menu->sectionOf($this->manifest(['section' => 'sales'])));
        $this->assertSame('logistics', $menu->sectionOf($this->manifest(['admin_menu' => [['group' => 'logistics']]])));
        $this->assertSame('system', $menu->sectionOf($this->manifest([])));
    }

    public function test_the_modules_screen_lists_every_module_with_its_state(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/modules')
            ->assertOk()
            ->assertSee('data-state="enabled"', false)
            ->assertSee('data-section="sales"', false);
    }

    /** A third party's SVG is a document that can carry script: sandboxed even when opened alone. */
    public function test_a_logo_is_served_sandboxed(): void
    {
        $manifest = $this->manifest(['logo' => 'assets/logo.svg']);
        $this->app->instance(\App\Core\Modules\ModuleManager::class, new class($this->app, $manifest) extends \App\Core\Modules\ModuleManager
        {
            public function __construct($app, protected ModuleManifest $look)
            {
                parent::__construct($app);
            }

            public function find(string $id): ?ModuleManifest
            {
                return $id === 'acme.look' ? $this->look : parent::find($id);
            }
        });

        $response = $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/modules/acme.look/logo')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');

        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));

        // A module with no picture of its own has nothing to serve.
        $this->get('http://localhost/admin/modules/torgash.catalog/logo')->assertNotFound();
    }
}
