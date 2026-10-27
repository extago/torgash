<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Tests\TestCase;
use Torgash\Checkout\Orders\OneClick;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Models\Zone;

/**
 * The delivery methods list in the admin's common shape — search, status
 * tabs, the status switched in place and for several at once — and a form
 * that saves and closes, or deletes, from its gear. The one-click nomination
 * is checkout's, named on the list through `shipping.methods.notes`.
 */
class ShippingMethodsListTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    public function test_search_and_status_tabs_narrow_the_list(): void
    {
        ShippingMethod::create(['name' => ['en' => 'Zebra courier', 'ru' => 'Курьер зебра'], 'enabled' => false]);

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/shipping/methods?q=зебра&status=disabled')
            ->assertOk()->assertSee('Zebra courier');

        $this->get('http://localhost/admin/shipping/methods?q=zebra&status=enabled')
            ->assertOk()->assertDontSee('Zebra courier');
    }

    public function test_the_list_says_where_a_method_is_offered_and_which_one_is_one_click(): void
    {
        $priced = ShippingMethod::create(['name' => ['en' => 'Priced courier'], 'enabled' => true]);
        $zone = Zone::create(['name' => ['en' => 'Listville zone']]);
        $priced->rates()->create(['zone_id' => $zone->id, 'base_rate' => 5_00, 'currency' => shop_currency(), 'enabled' => true]);
        ShippingMethod::create(['name' => ['en' => 'Unpriced courier'], 'enabled' => true]);
        app(OneClick::class)->nominateShipping((string) $priced->id, true);

        $this->actingAs($this->admin)->get('http://localhost/admin/shipping/methods')->assertOk()
            ->assertSeeInOrder(['Priced courier', 'one click', 'Listville zone'])
            ->assertSeeInOrder(['Unpriced courier', 'nowhere — no rates set']);
    }

    public function test_the_status_switches_in_place_and_in_bulk(): void
    {
        $one = ShippingMethod::create(['name' => ['en' => 'One'], 'enabled' => true]);
        $two = ShippingMethod::create(['name' => ['en' => 'Two'], 'enabled' => true]);

        $this->actingAs($this->admin)
            ->patch("http://localhost/admin/shipping/methods/{$one->id}/status", ['enabled' => 0])
            ->assertOk()->assertSee('shipping-method-status-'.$one->id, false);
        $this->assertFalse($one->refresh()->enabled);

        $this->post('http://localhost/admin/shipping/methods/bulk', ['action' => 'enable', 'ids' => [$one->id, $two->id]])->assertRedirect();
        $this->assertTrue($one->refresh()->enabled);

        $this->post('http://localhost/admin/shipping/methods/bulk', ['action' => 'delete', 'ids' => [$one->id, $two->id]])->assertRedirect();
        $this->assertSame(0, ShippingMethod::whereIn('id', [$one->id, $two->id])->count());
    }

    public function test_the_form_saves_and_closes_and_keeps_fields_outside_its_tabs(): void
    {
        $method = ShippingMethod::create(['name' => ['en' => 'Form courier'], 'enabled' => true, 'position' => 1]);

        $this->actingAs($this->admin)->get("http://localhost/admin/shipping/methods/{$method->id}/edit")->assertOk()
            ->assertSee('shipping-method-delete', false)
            ->assertSee('Nowhere yet: no zone has a rate for this method.');

        // Status and position sit beside the tabs, posted with form="" —
        // they arrive exactly as the fields inside the form do.
        $this->put("http://localhost/admin/shipping/methods/{$method->id}", [
            'name' => 'Form courier', 'position' => 7, 'after_save' => 'close',
        ])->assertRedirect(route('admin.shipping.methods.index'));

        $method->refresh();
        $this->assertSame(7, $method->position);
        $this->assertFalse($method->enabled, 'an unticked box is "off"');

        $this->delete("http://localhost/admin/shipping/methods/{$method->id}")->assertRedirect(route('admin.shipping.methods.index'));
        $this->assertNull(ShippingMethod::find($method->id));
    }
}
