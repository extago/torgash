<?php

namespace Tests\Feature\Core;

use App\Core\Admin\AdminMenu;
use App\Core\Admin\Preferences;
use App\Models\User;
use Tests\TestCase;

/**
 * The star beside a screen's heading puts it into the sidebar's
 * "Favourites" view — this person's own list of the screens they work in,
 * kept in admin_preferences beside the folded sidebar.
 */
class AdminFavouritesTest extends TestCase
{
    public function test_a_screen_is_starred_from_its_heading_and_drawn_in_the_favourites_list(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/orders')->assertOk()
            ->assertSee('data-favourite-toggle', false)
            ->assertSee('data-path="/admin/orders"', false)
            ->assertSee('data-sidebar-view="all"', false);

        $this->postJson(route('admin.preferences.favourite'), ['path' => url('/admin/orders'), 'on' => true])->assertNoContent();
        $this->postJson(route('admin.preferences.favourite'), ['path' => '/admin/customers', 'on' => true])->assertNoContent();

        $this->assertSame(['/admin/orders', '/admin/customers'], Preferences::get($admin->fresh(), AdminMenu::FAVOURITES));

        // The product's edit page belongs to the Products item: it is not
        // starred, while the orders list is.
        $html = $this->get('http://localhost/admin/orders')->assertOk()->getContent();
        $this->assertStringContainsString('data-path="/admin/orders" data-on="1"', $html);
        $this->assertMatchesRegularExpression('~data-admin-favourites>.*data-item-path="/admin/orders".*data-item-path="/admin/customers"~s', $html);

        $this->postJson(route('admin.preferences.favourite'), ['path' => '/admin/orders', 'on' => false])->assertNoContent();
        $this->assertSame(['/admin/customers'], Preferences::get($admin->fresh(), AdminMenu::FAVOURITES));
    }

    public function test_only_a_screen_of_the_sidebar_can_be_starred(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->postJson(route('admin.preferences.favourite'), ['path' => '/admin/no-such-screen', 'on' => true])->assertStatus(422);
        $this->postJson(route('admin.preferences.favourite'), ['path' => '/admin/orders/1/edit', 'on' => true])->assertStatus(422);
    }

    public function test_the_view_is_remembered_and_drawn_on_the_page(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin);

        $this->postJson(route('admin.preferences.view'), ['view' => 'favourites'])->assertNoContent();
        $this->get('http://localhost/admin')->assertOk()->assertSee('data-sidebar-view="favourites"', false);

        $this->postJson(route('admin.preferences.view'), ['view' => 'all'])->assertNoContent();
        $this->assertNull(Preferences::get($admin->fresh(), AdminMenu::VIEW));
        $this->get('http://localhost/admin')->assertOk()->assertSee('data-sidebar-view="all"', false);
    }

    public function test_the_dashboard_belongs_to_no_item_and_has_no_star(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin')->assertOk()->assertDontSee('data-favourite-toggle data-path', false);
    }
}
