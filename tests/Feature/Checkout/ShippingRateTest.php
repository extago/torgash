<?php

namespace Tests\Feature\Checkout;

use Tests\TestCase;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Models\ShippingRate;
use Torgash\Checkout\Shipping\Package;
use Torgash\Shipping\ZoneMatcher;

/**
 * What a rate charges. Every case here is a tariff a real shop writes down, and
 * every one of them is money: a band that charges per kilogram when it meant
 * per parcel is a 30-fold error nobody notices until the invoices arrive.
 */
class ShippingRateTest extends TestCase
{
    public function test_a_rate_with_no_bands_is_just_its_base(): void
    {
        $rate = $this->rate(base: 5_00);

        $this->assertSame(5_00, $rate->costFor(new Package(subtotal: 100_00, weight: 3000, items: 2)));
    }

    public function test_free_above_a_threshold_beats_every_band(): void
    {
        $rate = $this->rate(base: 5_00, freeOver: 100_00);
        $rate->rows()->create(['axis' => 'W', 'range_from' => 0, 'value_type' => 'fixed', 'value_amount' => 20_00]);

        $this->assertSame(0, $this->fresh($rate)->costFor(new Package(subtotal: 100_00, weight: 3000)));
        $this->assertSame(25_00, $this->fresh($rate)->costFor(new Package(subtotal: 99_00, weight: 3000)));
    }

    public function test_one_band_per_axis_is_charged_and_the_three_add_up(): void
    {
        $rate = $this->rate(base: 1_00);
        // 3.00 for an order under 50, 2.00 for a parcel under 5 kg, 0.50 an item.
        $rate->rows()->create(['axis' => 'C', 'range_from' => 0, 'range_to' => 50_00, 'value_amount' => 3_00]);
        $rate->rows()->create(['axis' => 'W', 'range_from' => 0, 'range_to' => 5000, 'value_amount' => 2_00]);
        $rate->rows()->create(['axis' => 'I', 'range_from' => 0, 'value_amount' => 50, 'per_unit' => true]);

        $cost = $this->fresh($rate)->costFor(new Package(subtotal: 40_00, weight: 4000, items: 3));

        $this->assertSame(1_00 + 3_00 + 2_00 + 150, $cost);
    }

    public function test_per_kilogram_charges_by_weight_not_by_gram(): void
    {
        $rate = $this->rate();
        $rate->rows()->create(['axis' => 'W', 'range_from' => 0, 'value_amount' => 2_00, 'per_unit' => true]);

        // 2.5 kg at 2.00 per kg — not 2500 times it, which is what charging by
        // the stored unit would do.
        $this->assertSame(5_00, $this->fresh($rate)->costFor(new Package(weight: 2500)));
    }

    public function test_a_percentage_is_taken_from_the_order_total_on_every_axis(): void
    {
        $rate = $this->rate();
        $rate->rows()->create([
            'axis' => 'W', 'range_from' => 5000, 'value_type' => 'percent', 'value_percent' => 5,
        ]);

        // "Anything over 5 kg costs 5% of the order" — 5% of 200, not of 6 kg.
        $this->assertSame(10_00, $this->fresh($rate)->costFor(new Package(subtotal: 200_00, weight: 6000)));
    }

    public function test_the_narrowest_matching_band_wins_and_a_gap_charges_nothing(): void
    {
        $rate = $this->rate();
        $rate->rows()->create(['axis' => 'C', 'range_from' => 0, 'range_to' => 100_00, 'value_amount' => 5_00]);
        $rate->rows()->create(['axis' => 'C', 'range_from' => 50_00, 'range_to' => 100_00, 'value_amount' => 2_00]);

        // Overlapping bands: the one that starts highest is the one the admin
        // reading the table top to bottom expects to apply.
        $this->assertSame(2_00, $this->fresh($rate)->costFor(new Package(subtotal: 60_00)));
        $this->assertSame(5_00, $this->fresh($rate)->costFor(new Package(subtotal: 20_00)));

        // Above every band: no band applies, so only the base is charged.
        $this->assertSame(0, $this->fresh($rate)->costFor(new Package(subtotal: 500_00)));
    }

    public function test_an_open_ended_band_covers_everything_above_it(): void
    {
        $rate = $this->rate();
        $rate->rows()->create(['axis' => 'I', 'range_from' => 10, 'value_amount' => 7_00]);

        $this->assertSame(7_00, $this->fresh($rate)->costFor(new Package(items: 999)));
        $this->assertSame(0, $this->fresh($rate)->costFor(new Package(items: 9)));
    }

    protected function rate(int $base = 0, ?int $freeOver = null): ShippingRate
    {
        $method = ShippingMethod::create(['name' => ['en' => 'Test courier'], 'position' => 90, 'enabled' => true]);

        return $method->rates()->create([
            'zone_id' => (new ZoneMatcher)->fallback()->id,
            'base_rate' => $base,
            'free_over' => $freeOver,
            'enabled' => true,
        ]);
    }

    /** costFor() reads the loaded rows relation, so a rate that just grew one needs reloading. */
    protected function fresh(ShippingRate $rate): ShippingRate
    {
        return ShippingRate::with('rows')->findOrFail($rate->id);
    }
}
