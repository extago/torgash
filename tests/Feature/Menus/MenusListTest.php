<?php

namespace Tests\Feature\Menus;

use App\Models\User;
use Tests\TestCase;
use Torgash\Menus\Models\Menu;

/**
 * Menus in the admin's common shape: search over names and the code, status
 * tabs, the status in place and for several at once, and forms — the menu's
 * and an item's — that stay open after Save.
 */
class MenusListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    public function test_search_tabs_status_and_bulk(): void
    {
        $on = Menu::create(['code' => 'quokka-footer', 'name' => ['en' => 'Quokka footer'], 'enabled' => true]);
        $off = Menu::create(['code' => 'quokka-side', 'name' => ['en' => 'Quokka side'], 'enabled' => false]);

        $this->get('http://localhost/admin/menus?q=quokka&status=disabled')->assertOk()
            ->assertSee('Quokka side')->assertDontSee('Quokka footer');
        $this->get('http://localhost/admin/menus?q=quokka-foot')->assertOk()->assertSee('Quokka footer');

        $this->patch("http://localhost/admin/menus/{$on->id}/status", ['enabled' => 0])
            ->assertOk()->assertSee('menu-status-'.$on->id, false);
        $this->assertFalse($on->fresh()->enabled);

        $this->post('http://localhost/admin/menus/bulk', ['action' => 'enable', 'ids' => [$on->id, $off->id]])->assertRedirect();
        $this->assertTrue($on->fresh()->enabled);
        $this->assertTrue($off->fresh()->enabled);
    }

    public function test_the_menu_and_item_forms_stay_open(): void
    {
        $menu = Menu::create(['code' => 'stay-menu', 'name' => ['en' => 'Stay menu'], 'enabled' => true]);

        $this->put("http://localhost/admin/menus/{$menu->id}", ['name' => 'Stay menu', 'code' => 'stay-menu', 'enabled' => 1])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.menus.edit', $menu));

        $created = $this->post("http://localhost/admin/menus/{$menu->id}/items", ['kind' => 'link', 'name' => 'Home', 'url' => '/', 'show_on' => 'all', 'enabled' => 1])
            ->assertSessionHasNoErrors();
        $item = $menu->items()->firstOrFail();
        $created->assertRedirect(route('admin.menus.items.edit', [$menu, $item]));

        // Save and close goes back to the tree, at the row just saved.
        $this->put("http://localhost/admin/menus/{$menu->id}/items/{$item->id}", ['kind' => 'link', 'name' => 'Home', 'url' => '/', 'show_on' => 'all', 'enabled' => 1, 'after_save' => 'close'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.menus.items', $menu).'#item-'.$item->id);
        $this->put("http://localhost/admin/menus/{$menu->id}/items/{$item->id}", ['kind' => 'link', 'name' => 'Home', 'url' => '/', 'show_on' => 'all', 'enabled' => 1])
            ->assertRedirect(route('admin.menus.items.edit', [$menu, $item]));
    }
}
