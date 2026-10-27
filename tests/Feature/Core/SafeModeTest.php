<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Support\SafeMode;
use App\Core\Themes\ThemeManager;
use App\Core\Themes\ThemeSettings;
use App\Models\Storefront;
use App\Models\User;
use Tests\TestCase;
use Torgash\Snippets\Models\Snippet;
use Torgash\Snippets\Placements;

/**
 * Safe mode (App\Core\Support\SafeMode): the storefront without the shop's
 * theme, its own CSS and its snippets — for the member of staff who turned
 * it on, or for everybody by .env, and for nobody else.
 */
class SafeModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $storefront = Storefront::where('is_default', true)->firstOrFail();
        $storefront->update(['theme' => 'crystal']);

        app(SettingsManager::class)->set(app(ThemeSettings::class)->moduleId('crystal'), 'custom_css', '.shop-own-rule{color:red}', $storefront->id);

        app(Placements::class)->flush();
        Snippet::create(['name' => 'Counter', 'placement' => 'head', 'code' => '<!--SHOP-SNIPPET-->', 'enabled' => true, 'rules_match' => 'all', 'rules' => []]);
    }

    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }

    public function test_the_shop_as_it_is_without_safe_mode(): void
    {
        $page = $this->actingAs($this->admin())->get('http://localhost/')->assertOk();

        $page->assertSee('.shop-own-rule', false);
        $page->assertSee('<!--SHOP-SNIPPET-->', false);
        $this->assertSame('crystal', app(ThemeManager::class)->active());
    }

    public function test_staff_turn_it_on_for_themselves(): void
    {
        $this->actingAs($this->admin())
            ->post('http://localhost/admin/safe-mode', ['on' => 1])
            ->assertRedirect();

        $page = $this->get('http://localhost/')->assertOk();

        $page->assertDontSee('.shop-own-rule', false);
        $page->assertDontSee('<!--SHOP-SNIPPET-->', false);
        $page->assertSee('data-staff-bar', false);
        $this->assertSame(SafeMode::THEME, app(ThemeManager::class)->active());

        $this->post('http://localhost/admin/safe-mode', ['on' => 0, 'back' => 'http://localhost/'])->assertRedirect('http://localhost/');
        $this->get('http://localhost/')->assertSee('<!--SHOP-SNIPPET-->', false);
    }

    public function test_the_flag_means_nothing_to_someone_who_is_not_staff(): void
    {
        $this->actingAs(User::factory()->create());

        $this->withSession([SafeMode::SESSION => true])->get('http://localhost/')
            ->assertOk()
            ->assertSee('<!--SHOP-SNIPPET-->', false)
            ->assertDontSee('data-staff-bar', false);
    }

    public function test_a_visitor_cannot_turn_it_on(): void
    {
        $this->post('http://localhost/admin/safe-mode', ['on' => 1])->assertRedirect();

        $this->get('http://localhost/')->assertSee('<!--SHOP-SNIPPET-->', false);
    }

    public function test_the_env_switch_is_for_everybody(): void
    {
        config(['torgash.safe_mode' => true]);

        $page = $this->get('http://localhost/')->assertOk();

        $page->assertDontSee('.shop-own-rule', false);
        $page->assertDontSee('<!--SHOP-SNIPPET-->', false);
        $page->assertDontSee('data-staff-bar', false);
    }
}
