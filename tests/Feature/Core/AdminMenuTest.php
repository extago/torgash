<?php

namespace Tests\Feature\Core;

use App\Core\Admin\AdminMenu;
use App\Core\Modules\ModuleManifest;
use App\Models\User;
use Tests\TestCase;

/**
 * The sidebar is data: sections, core's screens and the modules' items placed
 * by `group`. And a module's manifest may name its author and links, which
 * the modules screen prints — safely, since a third party wrote them.
 */
class AdminMenuTest extends TestCase
{
    public function test_items_land_in_their_sections_in_order(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())->get('http://localhost/admin')->assertOk();

        $sections = collect(app(AdminMenu::class)->sections())->keyBy('key');

        $this->assertSame(
            ['catalog', 'sales', 'marketing', 'seo', 'customers', 'logistics', 'content', 'data', 'security', 'system'],
            $sections->keys()->all(),
        );

        $titles = fn (string $key) => array_column($sections[$key]['items'], 'url');

        $this->assertContains(url('/admin/shipping/zones'), $titles('logistics'));
        $this->assertContains(url('/admin/reviews'), $titles('marketing'), 'the reviews item used to name a group nobody declared');
        $this->assertContains(route('admin.administrators.index'), $titles('security'));
        $this->assertContains(route('admin.modules'), $titles('system'));
        $this->assertSame([url('/admin/seo'), url('/admin/catalog/filter-pages'), route('admin.redirects.index')], $titles('seo'));
        $this->assertSame(url('/admin/promotions'), $titles('marketing')[0]);
    }

    public function test_an_unknown_group_goes_to_other_and_old_names_are_still_read(): void
    {
        $menu = new class(app(\App\Core\Modules\ModuleManager::class)) extends AdminMenu
        {
            public function coreItems(): array
            {
                return [
                    ['label' => 'Legacy', 'url' => '/admin/legacy', 'group' => 'store', 'icon' => 'tag'],
                    ['label' => 'Theme', 'url' => '/admin/theme', 'group' => 'appearance', 'icon' => 'tag'],
                    ['label' => 'Odd', 'url' => '/admin/odd', 'group' => 'nobody-declared-this', 'icon' => 'tag'],
                ];
            }
        };

        $where = [];
        foreach ($menu->sections() as $section) {
            foreach ($section['items'] as $item) {
                $where[$item['url']] = $section['key'];
            }
        }

        $this->assertSame('sales', $where[url('/admin/legacy')]);
        $this->assertSame('content', $where[url('/admin/theme')]);
        $this->assertSame('other', $where[url('/admin/odd')]);
    }

    /** A product's edit page belongs to "Products", and the longer address wins a shared start. */
    public function test_the_active_item_is_the_longest_address_the_page_is_under(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/shipping/zones')->assertOk();
        $active = collect(app(AdminMenu::class)->sections())->flatMap(fn ($s) => $s['items'])->where('active', true);

        $this->assertSame([url('/admin/shipping/zones')], $active->pluck('url')->values()->all());
    }

    public function test_staff_screens_are_left_out_for_someone_who_may_not_open_them(): void
    {
        $this->actingAs(User::factory()->create());

        $urls = collect(app(AdminMenu::class)->sections())->flatMap(fn ($s) => $s['items'])->pluck('url');

        $this->assertNotContains(route('admin.administrators.index'), $urls);
    }

    public function test_the_sidebar_renders_the_sections(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/shipping/zones')
            ->assertOk()
            ->assertSee('data-section="logistics"', false)
            ->assertSee('data-admin-sections', false);
    }

    // ------------------------------------------------------------ the author

    public function test_a_manifest_names_its_author_and_links_and_unsafe_addresses_are_dropped(): void
    {
        $manifest = $this->manifest([
            'author' => ['name' => 'Extago', 'url' => 'https://extago.com', 'email' => 'help@extago.com'],
            'links' => [
                ['label' => ['en' => 'Support', 'ru' => 'Поддержка'], 'url' => 'https://extago.com/support', 'icon' => 'life-buoy'],
                ['label' => 'Changelog', 'url' => 'javascript:alert(1)'],
                ['label' => 'Licence', 'url' => 'https://extago.com/licence', 'icon' => 'no-such-icon-anywhere'],
            ],
        ]);

        $this->assertSame(['name' => 'Extago', 'url' => 'https://extago.com', 'email' => 'help@extago.com'], $manifest->author());

        $links = $manifest->links();
        $this->assertCount(2, $links, 'a javascript: address is never offered as a link');
        $this->assertSame('Support', $links[0]['label']);
        // An icon nobody ships falls back rather than throwing under APP_DEBUG.
        $this->assertSame('external-link', $links[1]['icon']);
    }

    public function test_an_author_url_that_is_not_a_web_address_is_not_linked(): void
    {
        $manifest = $this->manifest(['author' => ['name' => 'Someone', 'url' => 'javascript:alert(1)']]);

        $this->assertSame(['name' => 'Someone', 'url' => null, 'email' => null], $manifest->author());
        $this->assertNull($this->manifest([])->author());
    }

    public function test_the_modules_screen_prints_the_author_line_only_where_there_is_one(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/modules')
            ->assertOk()
            // The engine's own modules name no author.
            ->assertDontSee(__('Author').':', false);
    }

    /** @param  array<string, mixed>  $extra */
    protected function manifest(array $extra): ModuleManifest
    {
        $dir = sys_get_temp_dir().'/torgash-manifest-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/manifest.json', json_encode($extra + [
            'id' => 'vendor.thing',
            'provider' => 'Vendor\\Thing\\Provider',
        ]));

        try {
            return ModuleManifest::fromFile($dir.'/manifest.json');
        } finally {
            unlink($dir.'/manifest.json');
            rmdir($dir);
        }
    }
}
