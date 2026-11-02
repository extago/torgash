<?php

namespace Tests\Feature\Shipping;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OrderTotals;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Models\ShippingRate;

/**
 * "The recipient pays the carrier on receipt" (PLAN §66, stage 4): Nova
 * Poshta "from 80". The shop charges nothing for it — not in the total, the
 * statistics or what the bank is asked for — and says what the carrier will
 * ask as an estimate, never "Free".
 */
class CarrierPaidDeliveryTest extends TestCase
{
    protected ShippingMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Product::create(['sku' => 'CP-1', 'name' => ['en' => 'Lamp'], 'slug' => 'cp-lamp', 'price' => 1000_00, 'stock' => 9, 'status' => 'active']);

        $this->method = ShippingMethod::whereNull('provider')->where('enabled', true)->orderBy('id')->firstOrFail();
        ShippingRate::where('shipping_method_id', $this->method->id)->update(['base_rate' => 80_00, 'free_over' => null]);
        $this->method->update(['paid_to_carrier' => true]);
    }

    public function test_the_order_charges_nothing_for_it_and_keeps_the_estimate(): void
    {
        $order = $this->place();

        $this->assertSame(0, $order->shipping_cost);
        $this->assertTrue($order->shipping_to_carrier);
        $this->assertSame(80_00, $order->shipping_carrier_cost);
        $this->assertSame(1000_00, $order->total, 'the goods alone');
        $this->assertSame(1000_00, $order->chargeNow(), 'the bank is asked for the goods alone');
        $this->assertSame($order->total, app(OrderTotals::class)->total($order));

        $said = __('≈ :amount, paid to the carrier on receipt', ['amount' => $order->money(80_00)]);
        $this->get('http://localhost/checkout/'.$order->order_number.'/confirmation')->assertOk()
            ->assertSee($said)->assertDontSee(__('Free'));
        $this->get('http://localhost/checkout/'.$order->order_number.'/invoice')->assertOk()->assertSee($said);
    }

    public function test_the_checkout_says_it_and_leaves_it_out_of_the_total(): void
    {
        $this->post('http://localhost/cart/add/cp-lamp', ['qty' => 1])->assertOk();

        $this->post('http://localhost/checkout/quote', ['city' => 'Town', 'shipping_method_id' => (string) $this->method->id])->assertOk()
            ->assertSee(__('≈ :amount, paid to the carrier on receipt', ['amount' => format_price(80_00, exact: true)]))
            ->assertSee(format_price(1000_00, exact: true));
    }

    public function test_a_free_rate_stays_free_and_a_surcharge_with_the_delivery_does_not_count_it(): void
    {
        $invoice = PaymentMethod::create([
            'name' => ['en' => 'Invoice'], 'enabled' => true, 'position' => -1,
            'surcharge_percent' => 10_00, 'surcharge_base' => 'goods_shipping',
        ]);

        $order = $this->place($invoice);
        $this->assertSame(100_00, $order->payment_surcharge, 'the carrier\'s 80 is not the shop\'s to add 10% to');

        ShippingRate::where('shipping_method_id', $this->method->id)->update(['base_rate' => 0]);
        $free = $this->place();
        $this->assertFalse($free->shipping_to_carrier, 'the shop pays a free delivery');
        $this->assertNull($free->shipping_carrier_cost);
    }

    public function test_a_manager_typing_the_cost_types_the_estimate(): void
    {
        $order = $this->place();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('http://localhost/admin/orders/'.$order->order_number)->assertOk()
            ->assertSee(__('≈ :amount, paid to the carrier on receipt', ['amount' => $order->adminMoney(80_00)]));

        $this->actingAs($admin)->patch('http://localhost/admin/shipping/orders/'.$order->id.'/method', [
            'shipping_method_name' => $order->shipping_method_name, 'shipping_cost' => '95',
        ]);

        $order->refresh();
        $this->assertSame([0, true, 95_00, 1000_00], [$order->shipping_cost, $order->shipping_to_carrier, $order->shipping_carrier_cost, $order->total]);
    }

    protected function place(?PaymentMethod $payment = null): Order
    {
        $this->post('http://localhost/cart/add/cp-lamp', ['qty' => 1])->assertOk();
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Buyer', 'email' => 'cp@example.test', 'address_line' => '1 Street', 'city' => 'Town',
            'shipping_method_id' => (string) $this->method->id,
            'payment_method_id' => ($payment ?? PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true]))->id,
        ])->assertSessionHasNoErrors();

        return Order::latest('id')->firstOrFail();
    }
}
