<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Themes\ThemeSettings;
use App\Models\Storefront;
use App\Models\User;
use Tests\TestCase;

/**
 * The header's account menu: the places somebody goes from any page, and the
 * rest of the account one click further — not the account sidebar poured
 * into a dropdown.
 */
class AccountMenuTest extends TestCase
{
    public function test_the_short_menu_keeps_the_quick_places_on_top_and_the_rest_folded(): void
    {
        $this->get('http://localhost/');
        $html = $this->actingAs(User::factory()->create())->get('http://localhost/')->assertOk()->getContent();
        $menu = $this->menu($html);

        [$quick, $rest] = explode('x-show="more"', $menu, 2);

        $this->assertStringContainsString(route('account.orders'), $quick);
        $this->assertStringContainsString(route('wishlist.index'), $quick);
        $this->assertStringNotContainsString(route('account.addresses.index'), $quick);
        $this->assertStringContainsString(route('account.addresses.index'), $rest);
        $this->assertStringNotContainsString(route('admin.dashboard'), $menu, 'a customer is not shown the control panel');
    }

    public function test_every_section_when_the_theme_says_so_and_the_control_panel_for_staff(): void
    {
        $this->get('http://localhost/');
        $themes = app(ThemeSettings::class);
        app(SettingsManager::class)->set($themes->moduleId(), 'account_menu', 'full', Storefront::query()->orderBy('id')->firstOrFail()->id);

        $staff = User::factory()->create();
        $staff->assignRole('admin');

        $menu = $this->menu($this->actingAs($staff)->get('http://localhost/')->assertOk()->getContent());

        $this->assertStringNotContainsString('x-show="more"', $menu);
        $this->assertStringContainsString(route('account.addresses.index'), $menu);
        $this->assertStringContainsString(route('admin.dashboard'), $menu);
    }

    protected function menu(string $html): string
    {
        $start = strpos($html, 'id="account-menu"');
        $this->assertNotFalse($start, 'the header draws the account menu');

        return substr($html, $start, (int) strpos($html, '</form>', $start) - $start);
    }
}
