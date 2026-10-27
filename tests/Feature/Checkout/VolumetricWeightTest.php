<?php

namespace Tests\Feature\Checkout;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\ShippingOptions;
use Torgash\Shipping\ZoneMatcher;

/**
 * Charging for the air in the box.
 *
 * Every carrier's "formula" is the same one — (L × W × H in cm) ÷ a divisor —
 * so this is a divisor and a choice, not an expression language: the existing
 * weight bands are simply read with a different weight. What differs between
 * shops is which weight they are billed on, and the answer for real carriers
 * is "whichever is greater", which is why that is a mode rather than a trick
 * an admin has to assemble out of two methods.
 */
class VolumetricWeightTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A pillow-shaped cart: 60 × 40 × 30 cm = 72 000 cm³, and 2 kg on the
        // scales. At the default divisor that is 20.7 kg volumetric.
        app(SettingsManager::class)->set('torgash.shipping', 'packing_factor', 1.15);
    }

    public function test_actual_weight_is_the_default_and_ignores_the_box(): void
    {
        $method = $this->method(['charge_on' => 'actual']);

        $this->assertSame(2_000, $method->billableWeight($this->package()));
    }

    public function test_volumetric_weight_is_the_box_over_the_divisor(): void
    {
        $method = $this->method(['charge_on' => 'volumetric', 'volumetric_divisor' => 4000]);

        // 72 000 cm³ × 1.15 ÷ 4000 = 20.7 kg.
        $this->assertSame(20_700, $method->billableWeight($this->package()));
    }

    public function test_a_bigger_divisor_charges_less(): void
    {
        $method = $this->method(['charge_on' => 'volumetric', 'volumetric_divisor' => 5000]);

        $this->assertSame(16_560, $method->billableWeight($this->package()));
    }

    public function test_chargeable_takes_whichever_is_greater(): void
    {
        $method = $this->method(['charge_on' => 'chargeable']);

        // The pillow: the box wins.
        $this->assertSame(20_700, $method->billableWeight($this->package()));
        // A battery of the same size: the scales win.
        $this->assertSame(30_000, $method->billableWeight($this->package(weight: 30_000)));
    }

    public function test_the_bands_are_read_with_that_weight(): void
    {
        // "Over 10 kg costs 50.00" — a 2 kg parcel that measures 20.7 kg
        // volumetric must fall into it, which is the whole point.
        $method = $this->method(['charge_on' => 'chargeable']);
        $rate = $method->rates()->create([
            'zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 0, 'enabled' => true,
        ]);
        $rate->rows()->create([
            'axis' => 'W', 'range_from' => 10_000, 'range_to' => null,
            'value_type' => 'fixed', 'value_amount' => 50_00, 'per_unit' => false,
        ]);

        $option = app(ShippingOptions::class)
            ->for(new Location, $this->package())
            ->firstWhere('method.id', $method->id);

        $this->assertSame(50_00, $option->cost);
    }

    public function test_a_method_charging_on_volume_says_so_when_the_box_is_unmeasured(): void
    {
        $method = $this->method(['charge_on' => 'volumetric', 'on_incalculable' => 'quote']);
        $rate = $method->rates()->create([
            'zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 0, 'enabled' => true,
        ]);
        $rate->rows()->create([
            'axis' => 'W', 'range_from' => 0, 'range_to' => null,
            'value_type' => 'fixed', 'value_amount' => 10_00, 'per_unit' => false,
        ]);

        $option = app(ShippingOptions::class)
            ->for(new Location, $this->package(volumeKnown: false))
            ->firstWhere('method.id', $method->id);

        // Not "weight missing": the scales are fine, it is the box that is not
        // measured, and an admin reading the order needs to know which.
        $this->assertSame([true, 'dimensions_missing'], [$option->pending_quote, $option->quote_reason]);
    }

    public function test_an_unmeasured_box_does_not_stop_a_method_that_charges_by_the_scales(): void
    {
        $method = $this->method(['charge_on' => 'actual']);
        $rate = $method->rates()->create([
            'zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 7_00, 'enabled' => true,
        ]);
        $rate->rows()->create([
            'axis' => 'W', 'range_from' => 0, 'range_to' => null,
            'value_type' => 'fixed', 'value_amount' => 0, 'per_unit' => false,
        ]);

        $option = app(ShippingOptions::class)
            ->for(new Location, $this->package(volumeKnown: false))
            ->firstWhere('method.id', $method->id);

        $this->assertFalse($option->pending_quote);
        $this->assertSame(7_00, $option->cost);
    }

    public function test_the_packing_factor_is_a_setting_not_a_constant(): void
    {
        app(SettingsManager::class)->set('torgash.shipping', 'packing_factor', 1.0);
        $method = $this->method(['charge_on' => 'volumetric']);

        // The goods' own volume, with no allowance for cardboard.
        $this->assertSame(18_000, $method->billableWeight($this->package()));
    }

    /** @param array<string, mixed> $attributes */
    protected function method(array $attributes): ShippingMethod
    {
        return ShippingMethod::create($attributes + [
            'name' => ['en' => 'Volumetric method'], 'position' => 95, 'enabled' => true,
        ]);
    }

    protected function package(int $weight = 2_000, bool $volumeKnown = true): Package
    {
        return new Package(
            subtotal: 100_00,
            weight: $weight,
            items: 1,
            weightKnown: true,
            volume: 72_000,
            volumeKnown: $volumeKnown,
        );
    }
}
