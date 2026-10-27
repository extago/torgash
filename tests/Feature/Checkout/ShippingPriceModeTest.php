<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\Checkout\FakeCarrier;
use Tests\TestCase;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\ProviderOffer;
use Torgash\Shipping\ShippingOptions;
use Torgash\Shipping\ZoneMatcher;

/**
 * How a method arrives at a price, and what it does when it cannot.
 *
 * The case that forced this: a catalogue where some products have no weight.
 * Before, a per-kilogram band silently charged as if those products weighed
 * nothing — no error, a wrong number on a real order. Now the method says so,
 * and the shop chooses in advance what "says so" means: hide, charge the bands
 * anyway, or take the order and price it by hand.
 *
 * Also pinned here: the carrier and the price mode are INDEPENDENT. "Use the
 * carrier's branch picker, charge my own table" must stay expressible.
 */
class ShippingPriceModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeCarrier::reset();
        hook_listen('shipping.providers', fn (array $p) => $p + ['fake' => FakeCarrier::class]);
    }

    public function test_free_is_free_whatever_the_bands_say(): void
    {
        $method = $this->method(['price_mode' => 'free'], bands: [['axis' => 'W', 'range_from' => 0, 'value_amount' => 50_00]]);

        $this->assertSame(0, $this->offered()->firstWhere('method.id', $method->id)?->cost);
    }

    public function test_a_weight_band_over_an_unweighed_cart_is_not_charged_as_weightless(): void
    {
        $method = $this->method(
            ['price_mode' => 'bands', 'on_incalculable' => 'quote'],
            bands: [['axis' => 'W', 'range_from' => 0, 'value_amount' => 50_00]],
        );

        $option = $this->offered(weightKnown: false)->firstWhere('method.id', $method->id);

        $this->assertTrue($option->pending_quote, 'a band it cannot compute must not be charged as if it could');
        $this->assertSame('weight_missing', $option->quote_reason);
        $this->assertSame(0, $option->cost);
    }

    public function test_a_rate_that_does_not_charge_by_weight_prices_an_unweighed_cart_fine(): void
    {
        // The distinction matters: refusing to price a flat rate because some
        // product has no weight would be theatre.
        $method = $this->method(['price_mode' => 'bands'], baseRate: 30_00);

        $option = $this->offered(weightKnown: false)->firstWhere('method.id', $method->id);

        $this->assertFalse($option->pending_quote);
        $this->assertSame(30_00, $option->cost);
    }

    public function test_the_fallback_can_hide_the_method_instead(): void
    {
        $method = $this->method(
            ['price_mode' => 'bands', 'on_incalculable' => 'hide'],
            bands: [['axis' => 'W', 'range_from' => 0, 'value_amount' => 50_00]],
        );

        $this->assertNull($this->offered(weightKnown: false)->firstWhere('method.id', $method->id));
    }

    public function test_the_fallback_can_charge_the_bands_anyway(): void
    {
        $method = $this->method(
            ['price_mode' => 'bands', 'on_incalculable' => 'bands'],
            baseRate: 10_00,
            bands: [['axis' => 'W', 'range_from' => 0, 'value_amount' => 5_00]],
        );

        $option = $this->offered(weightKnown: false)->firstWhere('method.id', $method->id);

        $this->assertFalse($option->pending_quote);
        $this->assertSame(15_00, $option->cost, 'base plus the band the zero weight falls into');
    }

    public function test_quote_on_request_is_offered_at_nothing_and_says_why(): void
    {
        $method = $this->method(['price_mode' => 'quote'], baseRate: 99_00);

        $option = $this->offered()->firstWhere('method.id', $method->id);

        $this->assertSame([true, 'quote_on_request', 0], [$option->pending_quote, $option->quote_reason, $option->cost]);
    }

    public function test_a_carrier_method_can_be_priced_by_the_shops_own_bands(): void
    {
        FakeCarrier::$offers = [new ProviderOffer(key: 'branch', label: 'to branch', cost: 80_00)];

        $method = $this->method(
            ['price_mode' => 'bands', 'provider' => 'fake'],
            baseRate: 25_00,
        );

        $option = $this->offered(location: new Location(country: 'UA'))->firstWhere('method.id', $method->id);

        // The carrier still says WHAT the variants are; the shop says how much.
        $this->assertSame('branch', $option->offer_key);
        $this->assertSame(25_00, $option->cost);
    }

    public function test_a_carrier_that_answers_nothing_falls_back(): void
    {
        FakeCarrier::$offers = [];

        $method = $this->method(['price_mode' => 'carrier', 'provider' => 'fake', 'on_incalculable' => 'quote']);

        $option = $this->offered(location: new Location(country: 'UA'))->firstWhere('method.id', $method->id);

        $this->assertSame([true, 'carrier_unavailable'], [$option->pending_quote, $option->quote_reason]);
    }

    public function test_a_carrier_that_answers_an_offer_but_no_price_is_not_free(): void
    {
        // The bug: the carrier returns a variant but cannot price this
        // destination (a village its API refuses), so cost is null. A null
        // slipping through as 0 rendered "Free" on the storefront.
        FakeCarrier::$offers = [new ProviderOffer(key: 'branch', label: 'to branch', cost: null)];

        $hidden = $this->method(['price_mode' => 'carrier', 'provider' => 'fake', 'on_incalculable' => 'hide']);
        $this->assertNull(
            $this->offered(location: new Location(country: 'UA'))->firstWhere('method.id', $hidden->id),
            'hide: a carrier method with no price is not offered',
        );

        $quoted = $this->method(['price_mode' => 'carrier', 'provider' => 'fake', 'on_incalculable' => 'quote']);
        $option = $this->offered(location: new Location(country: 'UA'))->firstWhere('method.id', $quoted->id);
        $this->assertSame([true, 'carrier_unavailable', 0], [$option->pending_quote, $option->quote_reason, $option->cost]);
    }

    public function test_a_priceless_carrier_offer_can_still_be_charged_by_the_bands(): void
    {
        // price_mode = bands never asks the carrier for a number, so a null
        // among its offers changes nothing — the shop's table prices it.
        FakeCarrier::$offers = [new ProviderOffer(key: 'branch', label: 'to branch', cost: null)];

        $method = $this->method(['price_mode' => 'bands', 'provider' => 'fake'], baseRate: 40_00);

        $option = $this->offered(location: new Location(country: 'UA'))->firstWhere('method.id', $method->id);

        $this->assertFalse($option->pending_quote);
        $this->assertSame(40_00, $option->cost);
    }

    public function test_an_order_placed_without_a_price_carries_the_reason_and_the_admin_sets_it(): void
    {
        $method = $this->method(['price_mode' => 'quote'], baseRate: 0);
        $order = $this->placeOrderWith($method);

        $this->assertTrue($order->shipping_details['pending_quote']);
        $this->assertSame('quote_on_request', $order->shipping_details['quote_reason']);
        $this->assertSame(0, $order->shipping_cost);

        $goodsAndTax = $order->total;

        $this->actingAs($this->admin())
            ->post("http://localhost/admin/shipping/orders/{$order->id}/cost", ['shipping_cost' => '45.50'])
            ->assertRedirect();

        $order->refresh();

        $this->assertSame(4550, $order->shipping_cost);
        $this->assertSame($goodsAndTax + 4550, $order->total, 'the total must grow by exactly the delivery');
        $this->assertFalse($order->shipping_details['pending_quote']);
        $this->assertSame('quote_on_request', $order->shipping_details['quote_reason'], 'why it happened is kept');
        // reorder(), because logs() sorts oldest-first: appending latest('id')
        // leaves the relation's own ORDER BY in front of it, and both entries
        // land in the same second.
        $this->assertStringContainsString(
            'Delivery priced',
            $order->logs()->reorder('id', 'desc')->first()->description,
        );
    }

    /** @param array<string, mixed> $attributes */
    protected function method(array $attributes, int $baseRate = 0, array $bands = []): ShippingMethod
    {
        $method = ShippingMethod::create($attributes + [
            'name' => ['en' => 'Mode method'], 'position' => 90, 'enabled' => true,
        ]);

        $rate = $method->rates()->create([
            'zone_id' => (new ZoneMatcher)->fallback()->id,
            'base_rate' => $baseRate,
            'enabled' => true,
        ]);

        foreach ($bands as $band) {
            $rate->rows()->create($band + ['range_to' => null, 'value_type' => 'fixed', 'per_unit' => false]);
        }

        return $method;
    }

    protected function offered(bool $weightKnown = true, ?Location $location = null)
    {
        return app(ShippingOptions::class)->for(
            $location ?? new Location,
            new Package(subtotal: 100_00, weight: 0, items: 1, weightKnown: $weightKnown),
        );
    }

    protected function placeOrderWith(ShippingMethod $method): Order
    {
        $product = \Torgash\Catalog\Models\Product::create([
            'sku' => 'PENDING-1', 'name' => ['en' => 'Thing'], 'slug' => 'pending-thing',
            'price' => 20_00, 'stock' => 5, 'status' => 'active',
        ]);
        $payment = \Torgash\Checkout\Models\PaymentMethod::first()
            ?? \Torgash\Checkout\Models\PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true]);

        $this->post('http://localhost/cart/add/'.$product->slug, ['qty' => 1]);

        $this->post('http://localhost/checkout', [
            'customer_name' => 'Buyer',
            'email' => 'buyer@test',
            'address_line' => 'Street 1',
            'city' => 'Town',
            'postal_code' => '01001',
            'country' => 'UA',
            'shipping_method_id' => (string) $method->id,
            'payment_method_id' => $payment->id,
        ])->assertRedirect();

        return Order::latest('id')->firstOrFail();
    }

    protected function admin(): User
    {
        $user = User::firstWhere('email', 'price-mode@test');

        if (! $user) {
            $user = User::create(['name' => 'Admin', 'email' => 'price-mode@test', 'password' => bcrypt('x')]);
            $user->assignRole(Role::findOrCreate('admin'));
        }

        return $user;
    }
}
