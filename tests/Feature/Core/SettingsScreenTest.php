<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Tests\TestCase;

/**
 * Thirty modules on one page: filed under the sidebar's own sections, in the
 * sidebar's order, with the shop's own settings first — so a module's settings
 * are where its screens are.
 */
class SettingsScreenTest extends TestCase
{
    public function test_modules_are_filed_under_the_sidebars_sections(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/settings')
            ->assertOk()
            ->assertSee('Find a setting…')
            // The shop first, then the sidebar's order: Catalog before Sales.
            ->assertSeeInOrder(['id="module-core"', 'id="module-torgash.catalog"', 'id="module-torgash.checkout"'], false);
    }

    public function test_a_module_is_named_in_the_readers_language(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->withSession(['admin_locale' => 'ru'])
            ->get('http://localhost/admin/settings')
            ->assertOk()
            ->assertSee('Оформление заказа')
            ->assertDontSee('<h2 class="card-title text-base">Checkout</h2>', false);
    }

    public function test_one_module_alone_has_no_table_of_contents(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/settings/torgash.catalog')
            ->assertOk()
            ->assertDontSee('data-settings-toc', false);
    }
}
