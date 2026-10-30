<?php

namespace Tests\Feature\Core;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The permission groups a shop actually makes — an order manager, a content
 * manager, an SEO specialist — against the screens: what each may open, what
 * answers 403, and that the sidebar offers nothing the reader cannot open.
 * Every module's screens are wrapped in the module's own permission, and a
 * menu item is gated by it unless it says otherwise.
 */
class StaffScenariosTest extends TestCase
{
    /** Permissions a content manager holds: everything but the system, the staff and the automations. */
    public const CONTENT_MANAGER = [
        'catalog.manage', 'sku.manage', 'media.manage', 'collections.manage', 'landings.manage', 'motivation.manage',
        'pages.manage', 'blog.manage', 'blocks.manage', 'blocks.html', 'menus.manage', 'banners.manage', 'banners.templates',
        'themes.manage', 'promotions.manage', 'reviews.moderate', 'comments.moderate', 'seo.manage', 'redirects.manage',
        'exim.manage', 'enquiries.manage', 'enquiries.forms', 'newsletter.manage',
    ];

    /** An order manager looks products up but edits none: catalog.view. */
    public const ORDER_MANAGER = ['orders.manage', 'enquiries.manage', 'customers.manage', 'rma.manage', 'shipping.manage', 'catalog.view'];

    public const SEO = ['seo.manage', 'redirects.manage', 'pages.manage', 'blog.manage'];

    public const MARKETER = ['promotions.manage', 'banners.manage', 'newsletter.manage', 'giftcards.manage', 'points.manage', 'blog.manage', 'catalog.view'];

    public const WAREHOUSE = ['warehouses.manage', 'inventory.manage', 'catalog.view'];

    /**
     * Screens, and which of the groups may open each. The lists of products
     * and categories and a product's card are the reader's (catalog.view,
     * which catalog.manage includes); making or changing one is the content
     * manager's alone.
     *
     * @return array<string, list<string>>
     */
    public static function screens(): array
    {
        return [
            '/admin/orders' => ['order'],
            '/admin/orders/create' => ['order'],
            '/admin/enquiries' => ['content', 'order'],
            '/admin/customers' => ['order'],
            '/admin/catalog/products' => ['content', 'order', 'marketer', 'warehouse'],
            '/admin/catalog/categories' => ['content', 'order', 'marketer', 'warehouse'],
            '/admin/catalog/products/create' => ['content'],
            '/admin/catalog/features' => ['content'],
            '/admin/kits' => ['content'],
            '/admin/options/groups' => ['content'],
            '/admin/promotions' => ['content', 'marketer'],
            '/admin/banners' => ['content', 'marketer'],
            '/admin/newsletter' => ['content', 'marketer'],
            '/admin/giftcards' => ['marketer'],
            '/admin/points' => ['marketer'],
            '/admin/checkout/coupons' => ['order'],
            '/admin/warehouses' => ['warehouse'],
            '/admin/inventory/documents' => ['warehouse'],
            '/admin/pages' => ['content', 'seo'],
            '/admin/blog' => ['content', 'seo', 'marketer'],
            '/admin/seo' => ['content', 'seo'],
            '/admin/redirects' => ['content', 'seo'],
            '/admin/blocks' => ['content'],
            '/admin/menus' => ['content'],
            '/admin/themes' => ['content'],
            '/admin/automations' => [],
            '/admin/settings' => [],
            '/admin/modules' => [],
            '/admin/system' => [],
            '/admin/administrators' => [],
            '/admin/guard/audit' => [],
            '/admin/snippets' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_every_permission_a_scenario_names_exists(): void
    {
        $known = Permission::query()->pluck('name')->all();

        foreach ([...self::CONTENT_MANAGER, ...self::ORDER_MANAGER, ...self::SEO, ...self::MARKETER, ...self::WAREHOUSE] as $permission) {
            $this->assertContains($permission, $known, "the permission {$permission} has no row — a group cannot be given it");
        }
    }

    public function test_each_group_opens_its_screens_and_no_other(): void
    {
        $groups = [
            'content' => $this->staff('Content manager', self::CONTENT_MANAGER),
            'order' => $this->staff('Order manager', self::ORDER_MANAGER),
            'seo' => $this->staff('SEO', self::SEO),
            'marketer' => $this->staff('Marketer', self::MARKETER),
            'warehouse' => $this->staff('Warehouse', self::WAREHOUSE),
        ];

        foreach (self::screens() as $path => $allowed) {
            foreach ($groups as $key => $user) {
                $response = $this->actingAs($user)->get('http://localhost'.$path);

                if (in_array($key, $allowed, true)) {
                    $this->assertSame(200, $response->status(), "{$key} should open {$path}");
                } else {
                    $this->assertSame(403, $response->status(), "{$key} should be refused {$path}");
                }
            }
        }
    }

    /**
     * The reader's catalogue: the list and the card open, with nothing on
     * them that changes a product; every change answers 403.
     */
    public function test_a_reader_of_the_catalogue_sees_but_changes_nothing(): void
    {
        $order = $this->staff('Order manager', self::ORDER_MANAGER);
        $product = \Torgash\Catalog\Models\Product::create([
            'sku' => 'READ-1', 'name' => ['en' => 'Read only'], 'slug' => 'read-1', 'price' => 10_00, 'stock' => 1, 'status' => 'active',
        ]);

        $this->actingAs($order)->get('http://localhost/admin/catalog/products')->assertOk()
            ->assertDontSee('href="http://localhost/admin/catalog/products/create"', false)
            ->assertDontSee('id="products-bulk"', false)
            ->assertDontSee('trash-product-'.$product->id, false);

        $this->get('http://localhost/admin/catalog/products/'.$product->id.'/edit')->assertOk()
            ->assertSee(__('Read only'))
            ->assertDontSee('name="after_save"', false);

        $this->put('http://localhost/admin/catalog/products/'.$product->id, ['sku' => 'X'])->assertForbidden();
        $this->patch('http://localhost/admin/catalog/products/'.$product->id.'/quick', ['price' => 1])->assertForbidden();
        $this->delete('http://localhost/admin/catalog/products/'.$product->id)->assertForbidden();
        $this->post('http://localhost/admin/catalog/products/bulk', ['ids' => [$product->id], 'action' => 'trash'])->assertForbidden();

        // The sidebar names the reader's two screens and not the editor's.
        $this->get('http://localhost/admin')->assertOk()
            ->assertSee('href="http://localhost/admin/catalog/products"', false)
            ->assertSee('href="http://localhost/admin/catalog/categories"', false)
            ->assertDontSee('href="http://localhost/admin/catalog/features"', false);
    }

    public function test_the_sidebar_offers_only_what_the_reader_may_open(): void
    {
        $order = $this->staff('Order manager', self::ORDER_MANAGER);

        $this->actingAs($order)->get('http://localhost/admin')->assertOk()
            ->assertSee('href="http://localhost/admin/orders"', false)
            ->assertDontSee('href="http://localhost/admin/catalog/features"', false)
            ->assertDontSee('href="http://localhost/admin/promotions"', false)
            ->assertDontSee('href="http://localhost/admin/kits"', false)
            ->assertDontSee('href="http://localhost/admin/automations"', false)
            ->assertDontSee('href="http://localhost/admin/settings"', false);

        $seo = $this->staff('SEO', self::SEO);

        $this->actingAs($seo)->get('http://localhost/admin')->assertOk()
            ->assertSee('href="http://localhost/admin/seo"', false)
            ->assertDontSee('href="http://localhost/admin/orders"', false);
    }

    public function test_every_module_menu_item_is_gated(): void
    {
        $open = [];
        foreach (app(ModuleManager::class)->adminMenu() as $item) {
            if (empty($item['can'])) {
                $open[] = $item['module'].': '.$item['label'];
            }
        }

        $this->assertSame([], $open, 'a menu item with no permission is offered to every manager and answers 403 to most');
    }

    /** @param  list<string>  $permissions */
    protected function staff(string $group, array $permissions): User
    {
        $role = Role::findOrCreate($group);
        $role->syncPermissions(['admin.access', ...$permissions]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }
}
