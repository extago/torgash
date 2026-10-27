<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OrderBasket;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Models\Zone;

/**
 * Re-quoting a placed order's delivery from the admin.
 *
 * The point of the whole exercise is that this is not a second pricing engine:
 * the same zones, rates and conditions that priced the order at checkout are
 * asked again, with the order standing in for the cart. So the tests here are
 * mostly "does the order get the answer the cart would have got".
 */
class OrderDeliveryRequoteTest extends TestCase
{
    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::role('admin')->firstOrFail();

        $this->product = Product::create([
            'sku' => 'REQ-1',
            'name' => ['en' => 'Requotable widget'],
            'slug' => 'requotable-widget',
            'price' => 50_00,
            'stock' => 10,
            'weight' => 800,
            'status' => 'active',
        ]);
    }

    public function test_the_block_offers_what_the_chosen_town_offers_and_not_what_it_does_not(): void
    {
        $this->kyivOnly('Kyiv courier', 7_00);
        $order = $this->order();

        $this->actingAs($this->admin)
            ->get($this->url($order).'?place_id=703448')
            ->assertOk()
            ->assertSee('Kyiv courier');

        // The same order, no town chosen: a zone-specific method is not on
        // offer, and the shop's catch-all ones are.
        $this->actingAs($this->admin)
            ->get($this->url($order))
            ->assertOk()
            ->assertDontSee('Kyiv courier');
    }

    public function test_applying_a_quote_writes_the_destination_method_and_price_and_reprices_the_order(): void
    {
        $method = $this->kyivOnly('Kyiv courier', 7_00);
        $order = $this->order();

        $this->actingAs($this->admin)
            ->patch($this->url($order), [
                'place_id' => 703448,
                'city' => 'Київ',
                'country' => 'UA',
                'address_line' => 'вул. Хрещатик, 1',
                'shipping_method_id' => (string) $method->id,
            ])
            ->assertNoContent()
            ->assertHeader('HX-Redirect', route('admin.orders.show', $order));

        $order->refresh();

        $this->assertSame(703448, (int) $order->place_id);
        $this->assertSame('Київ', $order->city);
        $this->assertSame('Kyiv courier', $order->shipping_method_name);
        $this->assertSame(7_00, $order->shipping_cost);
        $this->assertSame(100_00 + 7_00, $order->total);
        $this->assertTrue($order->logs()->where('description', 'like', 'Delivery re-quoted:%')->exists());
    }

    public function test_the_quoted_price_can_be_overruled_by_hand(): void
    {
        $method = $this->kyivOnly('Kyiv courier', 7_00);
        $order = $this->order();

        $this->actingAs($this->admin)->patch($this->url($order), [
            'place_id' => 703448,
            'city' => 'Київ',
            'shipping_method_id' => (string) $method->id,
            'shipping_cost' => '3.50',
        ])->assertNoContent();

        $order->refresh();

        $this->assertSame(3_50, $order->shipping_cost);
        $this->assertSame(100_00 + 3_50, $order->total);
    }

    /**
     * The same rule checkout enforces: whether a method is offered at all is a
     * property of the winning zone, so a hand-built request cannot apply one
     * that is not on offer to this address.
     */
    public function test_a_method_not_offered_for_that_address_is_refused_and_nothing_is_written(): void
    {
        $method = $this->kyivOnly('Kyiv courier', 7_00);
        $order = $this->order();

        $this->actingAs($this->admin)
            ->patch($this->url($order), [
                // No town, so the Kyiv-only method is not on offer.
                'shipping_method_id' => (string) $method->id,
            ])
            ->assertOk()
            ->assertSee('not offered', false);

        $order->refresh();

        $this->assertNull($order->shipping_method_name);
        $this->assertSame(0, $order->shipping_cost);
        $this->assertSame(100_00, $order->total);
    }

    public function test_an_order_placed_through_checkout_records_the_place_it_was_quoted_for(): void
    {
        $this->post('/cart/add/requotable-widget', ['qty' => 1])->assertOk();

        $this->post('/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'place_id' => 703448,
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ]);

        $this->assertSame(703448, (int) Order::latest('id')->firstOrFail()->place_id);
    }

    // ------------------------------------------------------------- the basket

    public function test_the_order_stands_in_for_a_cart_with_its_own_money_and_the_products_weight(): void
    {
        $order = $this->order();
        $basket = new OrderBasket($order);

        // Money is the order's: what the customer is being charged is a fact
        // about the order, not something to re-derive from today's prices.
        $this->assertSame(100_00, $basket->total());
        $this->assertSame(2, $basket->count());
        // Weight is the catalogue's: the parcel weighs what the goods weigh.
        $this->assertSame(1600, $basket->weight());
        $this->assertTrue($basket->weightIsKnown());
    }

    public function test_a_line_whose_product_is_gone_makes_the_weight_unknown(): void
    {
        $order = $this->order();
        $this->product->delete();

        $basket = new OrderBasket($order);

        $this->assertFalse($basket->weightIsKnown());
        // The units still ship, so they are still counted.
        $this->assertSame(2, $basket->count());
        $this->assertCount(0, $basket->items());
    }

    public function test_an_unweighed_product_makes_the_weight_unknown(): void
    {
        $this->product->update(['weight' => null]);

        $this->assertFalse((new OrderBasket($this->order()))->weightIsKnown());
    }

    // ---------------------------------------------------------------- helpers

    /** A method offered in Kyiv and nowhere else. */
    protected function kyivOnly(string $name, int $rate): ShippingMethod
    {
        $zone = Zone::create(['name' => ['en' => 'Kyiv'], 'position' => 0, 'enabled' => true]);
        $zone->rules()->create(['kind' => 'place', 'value' => '703448']);

        $method = ShippingMethod::create(['name' => ['en' => $name], 'position' => 90, 'enabled' => true]);
        $method->rates()->create(['zone_id' => $zone->id, 'base_rate' => $rate, 'enabled' => true]);

        return $method;
    }

    protected function order(): Order
    {
        $order = Order::create([
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'currency_code' => 'USD',
            'subtotal' => 100_00,
            'shipping_cost' => 0,
            'total' => 100_00,
            'status' => 'new',
            'stock_deducted' => false,
            'items' => [[
                'product_id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => 'Requotable widget',
                'qty' => 2,
                'unit_price' => 50_00,
                'line_total' => 100_00,
            ]],
        ]);

        $order->update(['order_number' => 'TG-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)]);

        return $order->fresh();
    }

    protected function url(Order $order): string
    {
        return 'http://localhost/admin/shipping/orders/'.$order->id.'/delivery';
    }
}
