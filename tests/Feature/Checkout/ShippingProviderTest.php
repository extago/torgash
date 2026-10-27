<?php

namespace Tests\Feature\Checkout;

use Tests\Fixtures\Checkout\FakeCarrier;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Models\Zone;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Shipping\ProviderOffer;
use Torgash\Shipping\ShippingOptions;
use Torgash\Shipping\ZoneMatcher;

/**
 * The seam a carrier module plugs into: a shipping method names a provider, and
 * from then on its price, its variants and its extra checkout fields come from
 * that provider instead of from rate bands — while the zone still decides
 * whether it is offered at all.
 *
 * A fake carrier stands in for Nova Poshta here; the real one is a module.
 */
class ShippingProviderTest extends TestCase
{
    protected ShippingMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        FakeCarrier::reset();

        hook_listen('shipping.providers', fn (array $p) => $p + ['fake' => FakeCarrier::class]);

        // A provider method priced BY that provider, offered (only) in the
        // catch-all zone. The two are separate settings on purpose — a carrier
        // method may also be priced by the shop's own bands (see
        // ShippingPriceModeTest) — so a test about carrier prices says so.
        $this->method = ShippingMethod::create([
            'name' => ['en' => 'Fake Carrier'], 'provider' => 'fake', 'price_mode' => 'carrier',
            'position' => 50, 'enabled' => true,
        ]);
        $this->method->rates()->create([
            'zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 0, 'enabled' => true,
        ]);
    }

    public function test_one_offer_per_variant_the_carrier_returns(): void
    {
        FakeCarrier::$offers = [
            new ProviderOffer(key: 'branch', label: 'to branch', cost: 30_00, deliveryTime: '1–2 days'),
            new ProviderOffer(key: 'courier', label: 'by courier', cost: 80_00),
        ];

        $options = $this->offered(new Location(country: 'UA'));

        $this->assertEqualsCanonicalizing(
            [$this->method->id.':branch', $this->method->id.':courier'],
            $options->pluck('id')->all()
        );
        $this->assertSame(30_00, $options->firstWhere('offer_key', 'branch')->cost);
        $this->assertSame('Fake Carrier — to branch', $options->firstWhere('offer_key', 'branch')->label);
        $this->assertSame('1–2 days', $options->firstWhere('offer_key', 'branch')->delivery_time);
    }

    public function test_a_place_the_carrier_does_not_serve_contributes_nothing(): void
    {
        FakeCarrier::$serves = false;
        FakeCarrier::$offers = [new ProviderOffer(key: 'branch', label: 'to branch', cost: 30_00)];

        $this->assertTrue($this->offered(new Location(country: 'UA'))->isEmpty());
        // The seeded flat-rate methods are untouched by any of this.
        $this->assertContains('Courier delivery', $this->allOffered(new Location(country: 'UA')));
    }

    public function test_a_provider_method_is_absent_until_the_address_is_known(): void
    {
        FakeCarrier::$offers = [new ProviderOffer(key: 'branch', label: 'to branch', cost: 30_00)];

        // The cart page before anyone has said where they are.
        $this->assertTrue($this->offered(new Location)->isEmpty());
    }

    public function test_a_provider_method_still_needs_a_rate_in_the_winning_zone(): void
    {
        FakeCarrier::$offers = [new ProviderOffer(key: 'branch', label: 'to branch', cost: 30_00)];

        // A narrower zone that wins for UA but has no rate for this method.
        $ua = Zone::create(['name' => ['en' => 'Ukraine'], 'enabled' => true]);
        $ua->rules()->create(['kind' => 'country', 'value' => 'UA']);

        $this->assertTrue(
            $this->offered(new Location(country: 'UA'))->isEmpty(),
            'the carrier was offered in a zone the merchant never priced it in'
        );
    }

    public function test_the_methods_config_reaches_the_carrier(): void
    {
        // One carrier, several methods: the config is how "to branch" and "by
        // courier" tell themselves apart.
        $this->method->update(['provider_config' => ['variant' => 'branch']]);
        FakeCarrier::$offers = [new ProviderOffer(key: 'branch', label: 'to branch', cost: 30_00)];

        $this->offered(new Location(country: 'UA'));

        $this->assertSame(['variant' => 'branch'], FakeCarrier::$lastConfig);
    }

    public function test_two_variants_of_one_carrier_are_gated_by_zone_independently(): void
    {
        FakeCarrier::$offers = [new ProviderOffer(key: 'x', label: 'variant', cost: 30_00)];

        // A second method of the SAME carrier, priced only in a Kyiv zone.
        $courierVariant = ShippingMethod::create([
            'name' => ['en' => 'Fake Carrier courier'], 'provider' => 'fake',
            'provider_config' => ['variant' => 'courier'], 'position' => 51, 'enabled' => true,
        ]);
        $kyiv = Zone::create(['name' => ['en' => 'Kyiv'], 'position' => -1, 'enabled' => true]);
        $kyiv->rules()->create(['kind' => 'place', 'value' => '703448']);
        $courierVariant->rates()->create(['zone_id' => $kyiv->id, 'base_rate' => 0, 'enabled' => true]);

        // In the catch-all zone only the branch method has a rate row.
        $names = $this->offered(new Location(country: 'UA'))->pluck('method.name.en')->all();

        $this->assertSame(['Fake Carrier'], $names, 'the courier variant leaked out of its Kyiv zone');
    }

    public function test_the_order_freezes_the_carriers_snapshot(): void
    {
        $this->method->update(['provider_config' => ['variant' => 'branch']]);
        FakeCarrier::$offers = [new ProviderOffer(key: 'branch', label: 'to branch', cost: 30_00)];
        $this->seedCart();

        $this->post('http://localhost/checkout', $this->form([
            'shipping_method_id' => $this->method->id.':branch',
            'shipping_fields' => ['branch' => 'Branch #5, Kyiv'],
        ]))->assertRedirect();

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(30_00, $order->shipping_cost);
        $this->assertSame('Fake Carrier — to branch', $order->shipping_method_name);
        // snapshot() saw the offer key, the submitted fields AND the method config.
        $this->assertSame(
            ['offer' => 'branch', 'branch' => 'Branch #5, Kyiv', 'variant' => 'branch'],
            $order->shipping_details
        );
    }

    public function test_the_carrier_is_handed_a_plan_of_shipments_not_a_package(): void
    {
        // One shipment today — but the carrier is asked to price a LIST, so a
        // module written now needs no rewrite the day a cart leaves two
        // warehouses at once (PLAN §12.8).
        FakeCarrier::$offers = [new ProviderOffer(key: 'branch', label: 'to branch', cost: 30_00)];

        $this->offered(new Location(country: 'UA'));

        $this->assertCount(1, FakeCarrier::$lastShipments);
        $this->assertInstanceOf(\Torgash\Shipping\Shipment::class, FakeCarrier::$lastShipments[0]);
        $this->assertSame(0, FakeCarrier::$lastShipments[0]->package->items);
    }

    public function test_missing_carrier_fields_block_the_order(): void
    {
        FakeCarrier::$offers = [new ProviderOffer(key: 'branch', label: 'to branch', cost: 30_00)];
        $this->seedCart();

        $this->post('http://localhost/checkout', $this->form([
            'shipping_method_id' => $this->method->id.':branch',
            // no shipping_fields
        ]))->assertSessionHasErrors('shipping_fields');

        $this->assertSame(0, Order::count());
    }

    public function test_a_flat_rate_method_is_unaffected(): void
    {
        $courier = $this->allOffered(new Location(country: 'UA'), keyed: true)
            ->firstWhere('method.name.en', 'Courier delivery');

        $this->assertNotNull($courier);
        $this->assertNull($courier->offer_key);
        $this->assertSame((string) $courier->method->id, $courier->id);
    }

    /** Options contributed by the fake carrier. */
    protected function offered(Location $location): \Illuminate\Support\Collection
    {
        return $this->allOffered($location, keyed: true)->where('provider', 'fake')->values();
    }

    protected function allOffered(Location $location, bool $keyed = false): \Illuminate\Support\Collection
    {
        $options = app(ShippingOptions::class)->for($location, new Package(subtotal: 10_00));

        return $keyed ? $options : $options->map(fn ($o) => $o->method->name['en']);
    }

    protected function seedCart(): void
    {
        Product::create([
            'sku' => 'SHIP-P', 'name' => ['en' => 'Shippable'], 'slug' => 'shippable-p',
            'price' => 10_00, 'stock' => 5, 'status' => 'active',
        ]);
        $this->post('http://localhost/cart/add/shippable-p', ['qty' => 1])->assertOk();
    }

    /** @param array<string, mixed> $overrides */
    protected function form(array $overrides): array
    {
        return $overrides + [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Kyiv',
            'country' => 'UA',
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ];
    }
}
