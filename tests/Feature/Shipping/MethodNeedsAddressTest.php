<?php

namespace Tests\Feature\Shipping;

use Tests\TestCase;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\ShippingOptions;
use Torgash\Shipping\ZoneMatcher;

/**
 * A method with no carrier says itself whether the order goes to an address.
 * The seeded "Pickup" demanded a street the order would never be taken to
 * (found on the first live shop, 2026-10-21).
 */
class MethodNeedsAddressTest extends TestCase
{
    public function test_a_plain_method_can_say_it_needs_no_address(): void
    {
        $collect = $this->method('Collect it', needsAddress: false);
        $courier = $this->method('Bring it', needsAddress: true);

        $options = app(ShippingOptions::class)->for(new Location(country: 'UA'), new Package(subtotal: 10_00));

        $this->assertFalse($options->firstWhere('method.id', $collect->id)->needs_address);
        $this->assertTrue($options->firstWhere('method.id', $courier->id)->needs_address);
    }

    public function test_the_seeded_pickup_needs_no_address(): void
    {
        $pickup = ShippingMethod::query()->whereNull('provider')->whereRaw("name->>'en' = 'Pickup'")->first();

        $this->assertNotNull($pickup);
        $this->assertFalse($pickup->needs_address);
    }

    protected function method(string $name, bool $needsAddress): ShippingMethod
    {
        $method = ShippingMethod::create([
            'name' => ['en' => $name], 'price_mode' => 'bands', 'position' => 90,
            'enabled' => true, 'needs_address' => $needsAddress,
        ]);
        $method->rates()->create(['zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 0, 'enabled' => true]);

        return $method;
    }
}
