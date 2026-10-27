<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Tests\TestCase;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Models\Zone;

/**
 * Shipping zones in the admin's common shape: search, status tabs, the status
 * in place and for several at once, what each zone actually OFFERS beside
 * what it covers, and a form that stays open after Save.
 */
class ShippingZonesListTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    public function test_search_tabs_and_what_a_zone_offers(): void
    {
        $priced = Zone::create(['name' => ['en' => 'Quokka zone', 'ru' => 'Зона квокка'], 'enabled' => true]);
        $priced->rules()->create(['kind' => 'country', 'value' => 'UA']);
        $method = ShippingMethod::create(['name' => ['en' => 'Quokka courier'], 'enabled' => true]);
        $method->rates()->create(['zone_id' => $priced->id, 'base_rate' => 3_00, 'currency' => shop_currency(), 'enabled' => true]);
        Zone::create(['name' => ['en' => 'Quokka empty'], 'enabled' => false]);

        $this->actingAs($this->admin)->get('http://localhost/admin/shipping/zones?q=квокка')->assertOk()
            ->assertSeeInOrder(['Quokka zone', 'Quokka courier'])
            ->assertDontSee('Quokka empty');

        $this->get('http://localhost/admin/shipping/zones?q=quokka&status=disabled')->assertOk()
            ->assertSeeInOrder(['Quokka empty', 'nothing — matches no one', 'no method has a rate here'])
            ->assertDontSee('Quokka zone');
    }

    public function test_the_status_switches_in_place_and_in_bulk(): void
    {
        $one = Zone::create(['name' => ['en' => 'One'], 'enabled' => true]);
        $two = Zone::create(['name' => ['en' => 'Two'], 'enabled' => true]);

        $this->actingAs($this->admin)
            ->patch("http://localhost/admin/shipping/zones/{$one->id}/status", ['enabled' => 0])
            ->assertOk()->assertSee('zone-status-'.$one->id, false);
        $this->assertFalse($one->refresh()->enabled);

        $this->post('http://localhost/admin/shipping/zones/bulk', ['action' => 'disable', 'ids' => [$two->id]])->assertRedirect();
        $this->assertFalse($two->refresh()->enabled);

        $this->post('http://localhost/admin/shipping/zones/bulk', ['action' => 'delete', 'ids' => [$one->id, $two->id]])->assertRedirect();
        $this->assertSame(0, Zone::whereIn('id', [$one->id, $two->id])->count());
    }

    public function test_saving_stays_on_the_form_and_close_goes_back(): void
    {
        $zone = Zone::create(['name' => ['en' => 'Stay zone'], 'enabled' => true]);

        $this->actingAs($this->admin)->get("http://localhost/admin/shipping/zones/{$zone->id}/edit")->assertOk()
            ->assertSee('No shipping method has a rate here yet, so a customer in this zone is offered nothing.');

        $this->put("http://localhost/admin/shipping/zones/{$zone->id}", ['names' => ['en' => 'Stay zone'], 'enabled' => 1, 'countries_text' => 'UA'])
            ->assertRedirect(route('admin.shipping.zones.edit', $zone));
        $this->put("http://localhost/admin/shipping/zones/{$zone->id}", ['names' => ['en' => 'Stay zone'], 'after_save' => 'close'])
            ->assertRedirect(route('admin.shipping.zones.index'));
        $this->assertFalse($zone->refresh()->enabled, 'an unticked box is "off"');
    }
}
