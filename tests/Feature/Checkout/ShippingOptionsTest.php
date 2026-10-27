<?php

namespace Tests\Feature\Checkout;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Models\Zone;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Shipping\ShippingOptions;
use Torgash\Shipping\ZoneMatcher;

/**
 * Which methods a customer is offered.
 *
 * "No rate in this zone means not offered here" is the whole feature the shop
 * owner asked for — pickup in our own city, courier everywhere else — and it is
 * also the rule that silently hides a method from a whole country if a rate is
 * forgotten, so both directions are pinned here.
 */
class ShippingOptionsTest extends TestCase
{
    public function test_a_method_is_offered_only_where_it_has_a_rate(): void
    {
        $kyiv = $this->zone('Kyiv', [['kind' => 'place', 'value' => '703448']]);

        // A name of its own: the shop is seeded with a Pickup that has a
        // catch-all rate, and this test is about the one that does not.
        $pickup = $this->method('Pickup from our shop');
        $pickup->rates()->create(['zone_id' => $kyiv->id, 'base_rate' => 0, 'enabled' => true]);

        $inKyiv = $this->offered(new Location(country: 'UA', placeId: 703448));
        $elsewhere = $this->offered(new Location(country: 'PL'));

        $this->assertContains('Pickup from our shop', $inKyiv);
        $this->assertNotContains('Pickup from our shop', $elsewhere);
    }

    public function test_the_zone_that_wins_is_the_only_one_that_offers_anything(): void
    {
        // A narrower zone does not inherit the wider one's rates: whoever
        // creates a city zone is saying "here is what I do in this city".
        $kyiv = $this->zone('Kyiv', [['kind' => 'place', 'value' => '703448']]);
        $this->method('Pickup from our shop')
            ->rates()->create(['zone_id' => $kyiv->id, 'base_rate' => 0, 'enabled' => true]);

        $offered = $this->offered(new Location(country: 'UA', placeId: 703448));

        $this->assertSame(['Pickup from our shop'], $offered, 'the catch-all rates leaked into a narrower zone');
    }

    public function test_an_unknown_address_is_priced_as_everywhere(): void
    {
        // Before the customer says where they are, the shop can still quote the
        // rates it set for "everywhere" — that is what they mean.
        $offered = $this->offered(new Location);

        $this->assertContains('Courier delivery', $offered);
    }

    public function test_a_disabled_rate_and_a_disabled_method_are_both_hidden(): void
    {
        $everywhere = (new ZoneMatcher)->fallback();

        $offered = $this->offered(new Location(country: 'PL'));
        $this->assertContains('Pickup', $offered);

        // The rate switched off, the method left alone.
        DB::table('m_shipping_rates')
            ->where('shipping_method_id', ShippingMethod::where('name->en', 'Pickup')->value('id'))
            ->where('zone_id', $everywhere->id)
            ->update(['enabled' => false]);

        // The method switched off, its rate left alone.
        ShippingMethod::where('name->en', 'Courier delivery')->update(['enabled' => false]);

        $offered = $this->offered(new Location(country: 'PL'));

        $this->assertNotContains('Pickup', $offered);
        $this->assertNotContains('Courier delivery', $offered);
    }

    public function test_the_price_shown_comes_from_the_rate_in_the_winning_zone(): void
    {
        $kyiv = $this->zone('Kyiv', [['kind' => 'place', 'value' => '703448']]);
        $courier = ShippingMethod::where('name->en', 'Courier delivery')->firstOrFail();
        $courier->rates()->create(['zone_id' => $kyiv->id, 'base_rate' => 2_00, 'enabled' => true]);

        $options = app(ShippingOptions::class)
            ->for(new Location(country: 'UA', placeId: 703448), new Package(subtotal: 10_00));

        $this->assertSame(2_00, $options->firstOrFail()->cost);
    }

    public function test_an_order_cannot_be_placed_with_a_method_that_is_not_offered(): void
    {
        Product::create([
            'sku' => 'SHIP-1',
            'name' => ['en' => 'Shippable'],
            'slug' => 'shippable',
            'price' => 10_00,
            'stock' => 5,
            'status' => 'active',
        ]);

        // Priced only in a zone this cart will never land in.
        $kyiv = $this->zone('Kyiv', [['kind' => 'place', 'value' => '703448']]);
        $cityOnly = $this->method('City only');
        $cityOnly->rates()->create(['zone_id' => $kyiv->id, 'base_rate' => 0, 'enabled' => true]);

        $this->post('http://localhost/cart/add/shippable', ['qty' => 1])->assertOk();
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => $cityOnly->id,
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ])->assertSessionHasErrors('shipping_method_id');

        $this->assertSame(0, Order::count());
    }

    /** @return list<string> names of the methods offered there */
    protected function offered(Location $location): array
    {
        return app(ShippingOptions::class)->for($location, new Package(subtotal: 10_00))
            ->map(fn (object $option) => $option->method->name['en'])
            ->all();
    }

    protected function method(string $name): ShippingMethod
    {
        return ShippingMethod::create(['name' => ['en' => $name], 'position' => 90, 'enabled' => true]);
    }

    /** @param  list<array{kind: string, value: string}>  $rules */
    protected function zone(string $name, array $rules): Zone
    {
        $zone = Zone::create(['name' => ['en' => $name], 'position' => 0, 'enabled' => true]);

        foreach ($rules as $rule) {
            $zone->rules()->create($rule);
        }

        return $zone;
    }
}
