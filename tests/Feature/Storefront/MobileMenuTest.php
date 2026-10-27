<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;

/**
 * The phone version (PLAN §38 step 3): a tab bar modules add to, a drawer
 * whose whole content is a zone of blocks, and the blocks it is laid out with.
 */
class MobileMenuTest extends TestCase
{
    /** The bar is the theme's `tabbar` menu of shortcuts (PLAN §39.6), in its order, with a badge slot each. */
    public function test_the_tab_bar_collects_tabs_from_modules(): void
    {
        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $bar = substr($html, (int) strpos($html, 'aria-label="Main sections"'));
        $positions = array_map(fn ($key) => strpos($bar, 'id="tabbar-badge-'.$key.'"'), ['home', 'wishlist', 'cart', 'account']);

        foreach ($positions as $position) {
            $this->assertNotFalse($position);
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'in the order of the menu');
        $this->assertStringContainsString("\$dispatch('torgash-drawer', true)", $bar, 'Catalogue opens the drawer');
    }

    /** Adding to the basket refreshes the tab's badge out of band, beside the header's. */
    public function test_an_add_refreshes_the_tab_badge(): void
    {
        Product::create(['sku' => 'MOB-1', 'name' => ['en' => 'Pocket kettle'], 'slug' => 'mob-1', 'price' => 100_00, 'stock' => 5, 'status' => 'active']);

        $html = $this->post(route('cart.add', 'mob-1'), ['qty' => 2])->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="tabbar-badge-cart"[^>]*hx-swap-oob/', $html);
    }

    /** The drawer draws its zone: a guest's account band, the catalogue menu opening in panels, the links menu. */
    public function test_the_drawer_is_its_zone_of_blocks(): void
    {
        $root = Category::create(['name' => ['en' => 'Tools'], 'slug' => 'mob-tools', 'icon' => 'hammer', 'enabled' => true]);
        Category::create(['name' => ['en' => 'Drills'], 'slug' => 'mob-drills', 'parent_id' => $root->id, 'enabled' => true]);

        $html = $this->get('http://localhost/')->assertOk()->getContent();
        $drawer = substr($html, (int) strpos($html, 'role="dialog" aria-modal="true" aria-label="Menu"'));

        $this->assertStringContainsString('torgash-block-account', $drawer);
        $this->assertStringContainsString(route('account.login'), $drawer);
        $this->assertStringContainsString('torgash-menu-drawer', $drawer);
        $this->assertStringContainsString('Everything in Tools', $drawer, 'a department with children opens a panel');
        $this->assertStringContainsString('torgash-menu-rows', $drawer);
    }

    /** A signed-in customer sees their name and orders, not "Sign in". */
    public function test_the_account_block_knows_who_is_there(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Olena']));

        $html = view('torgash.blocks::blocks.account', ['settings' => ['note' => 'Why sign in']])->render();

        $this->assertStringContainsString('Olena', $html);
        $this->assertStringContainsString(route('account.orders'), $html);
        $this->assertStringNotContainsString('Why sign in', $html);
    }

    /** Only addresses become links and only known icons are drawn; "!" sets a line off. */
    public function test_the_links_block_prints_only_addresses(): void
    {
        $html = view('torgash.blocks::blocks.links', ['settings' => ['items' => implode("\n", [
            '!Sale | /sale | badge-percent | On sale',
            'Evil | javascript:alert(1) | heart',
            'Away | //evil.example | not-an-icon',
            '<b>Bold</b> | https://example.com',
        ])]])->render();

        $this->assertStringContainsString('href="/sale"', $html);
        $this->assertStringContainsString('text-accent', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt;', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertSame(2, substr_count($html, '<svg'), 'badge-percent and heart; the unknown icon is not drawn');
    }

    /** On a phone the filter panel is a sheet behind a "Filters" button — the SAME aside, not a copy. */
    public function test_the_listing_hides_its_filters_behind_a_button_on_a_phone(): void
    {
        $html = $this->get('http://localhost/catalog')->assertOk()->getContent();

        $this->assertStringContainsString('x-on:click="filtersOpen = true"', $html);
        $this->assertStringContainsString("x-bind:class=\"filtersOpen && 'is-open'\"", $html);
        $this->assertStringContainsString('torgash-sheet ', $html, 'a sheet only under .torgash-js — without a script the panel stays in the page');
        $this->assertStringContainsString('torgash-js-only lg:hidden btn', $html, 'the button that needs a script is drawn only where one runs');
        $this->assertSame(1, substr_count($html, 'name="price_min"'), 'one set of controls');
    }

    /** Past the buy button a strip over the tab bar submits the SAME form — one quantity, one set of answers. */
    public function test_the_buy_strip_submits_the_page_form(): void
    {
        $product = Product::create(['sku' => 'MOB-2', 'name' => ['en' => 'Strip kettle'], 'slug' => 'mob-2', 'price' => 100_00, 'stock' => 5, 'status' => 'active']);

        $html = $this->get('http://localhost/product/mob-2')->assertOk()->getContent();
        $strip = substr($html, (int) strpos($html, 'torgash-buybar'));

        $this->assertStringContainsString('id="buy-box-'.$product->id.'-form"', $html);
        $this->assertStringContainsString('form="buy-box-'.$product->id.'-form"', $strip);
        $this->assertStringContainsString('Strip kettle', $strip);

        $dialog = $this->get(route('cart.choose', 'mob-2'))->getContent();
        $this->assertStringNotContainsString('torgash-buybar', $dialog, 'the choice window is the box already');
    }

    /** Without a script "Catalogue" in the tab bar is still a link to the catalogue. */
    public function test_the_catalogue_tab_is_a_link_before_it_is_a_button(): void
    {
        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringContainsString('<a href="'.route('catalog.index').'" x-data x-on:click.prevent="$dispatch(\'torgash-drawer\', true)"', $html);
    }

    /** A category icon is a Lucide name or nothing. */
    public function test_a_category_icon_must_be_an_icon_name(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post('http://localhost/admin/catalog/categories', [
            'name' => 'Iconic', 'slug' => 'mob-iconic', 'icon' => '../../etc/passwd',
        ])->assertSessionHasErrors('icon');

        $this->actingAs($admin)->post('http://localhost/admin/catalog/categories', [
            'name' => 'Iconic', 'slug' => 'mob-iconic', 'icon' => 'hammer',
        ])->assertSessionHasNoErrors();

        $this->assertSame('hammer', Category::where('slug', 'mob-iconic')->value('icon'));
    }
}
