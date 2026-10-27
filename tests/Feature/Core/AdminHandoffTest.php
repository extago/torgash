<?php

namespace Tests\Feature\Core;

use App\Models\Storefront;
use App\Models\User;
use Tests\TestCase;

/**
 * A storefront with an address of its own is configured THERE: the preview
 * frame and the draft in the session must share an origin with the
 * configurator, and a frame from another site gets no cookies. The themes
 * screen signs the person in on that address with a one-use ticket
 * (App\Core\Access\Handoff).
 */
class AdminHandoffTest extends TestCase
{
    protected User $admin;

    protected Storefront $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $main = Storefront::query()->where('is_default', true)->firstOrFail();
        $this->second = Storefront::create([
            'code' => 'second', 'name' => 'Second Shop', 'domains' => ['second.test'],
            'theme' => 'default', 'default_locale' => $main->default_locale, 'locales' => $main->locales,
            'default_currency' => $main->default_currency, 'currencies' => [$main->default_currency],
            'is_default' => false, 'enabled' => true,
        ]);
    }

    public function test_the_themes_screen_sends_a_storefront_with_its_own_address_there(): void
    {
        $this->actingAs($this->admin)->get('http://localhost/admin/themes')->assertOk()
            ->assertSee(route('admin.themes.open', $this->second), false)
            ->assertSee('second.test')
            ->assertDontSee('href="'.route('admin.themes.customize', $this->second).'"', false);

        // Opened by hand on the wrong address, it does not draw a frame that cannot work.
        $this->get('http://localhost/admin/themes/customize/'.$this->second->id)
            ->assertRedirect(route('admin.themes.index'));
    }

    public function test_the_ticket_signs_in_on_the_storefront_address_once(): void
    {
        $handoff = $this->ticket();

        // A new browser on the other address: nobody is signed in there.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->get($handoff)->assertRedirect('http://second.test/admin/themes/customize/'.$this->second->id);
        $this->assertAuthenticatedAs($this->admin);

        $this->get('http://second.test/admin/themes/customize/'.$this->second->id)->assertOk();

        // Spent.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get($handoff)->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_a_ticket_is_good_only_on_its_storefront_address(): void
    {
        $handoff = $this->ticket();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->get(str_replace('http://second.test', 'http://elsewhere.test', $handoff))->assertRedirect();
        $this->assertGuest();
    }

    public function test_a_second_factor_already_answered_comes_along(): void
    {
        $this->actingAs($this->admin)->withSession(['guard.two_factor' => ['user' => $this->admin->id, 'at' => now()->timestamp]]);
        $handoff = $this->ticket();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->get($handoff)->assertSessionHas('guard.two_factor.user', $this->admin->id);
    }

    public function test_only_a_post_issues_a_ticket(): void
    {
        $response = $this->actingAs($this->admin)->get('http://localhost/admin/themes/customize/'.$this->second->id.'/open');

        $this->assertFalse($response->isRedirect(), 'a link prefetcher would spend the ticket');
    }

    protected function ticket(): string
    {
        $response = $this->actingAs($this->admin)->post('http://localhost/admin/themes/customize/'.$this->second->id.'/open');
        $location = (string) $response->headers->get('Location');

        $this->assertMatchesRegularExpression('~^http://second\.test/admin/handoff/[A-Za-z0-9]{48}$~', $location);

        return $location;
    }
}
