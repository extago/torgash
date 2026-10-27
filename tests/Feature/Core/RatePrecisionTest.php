<?php

namespace Tests\Feature\Core;

use App\Models\Currency;
use Tests\TestCase;

/**
 * A rate keeps its significant digits, not eight decimal places: one dollar
 * is about 0.0000105 BTC, and eight places kept three digits of it.
 */
class RatePrecisionTest extends TestCase
{
    public function test_a_tiny_rate_survives_the_database_and_prints_readably(): void
    {
        Currency::updateOrCreate(['code' => 'XBT'], ['symbol' => '₿', 'name' => 'Bitcoin', 'rate' => 0.0000001049123, 'decimals' => 8, 'enabled' => true]);

        $this->assertEqualsWithDelta(0.0000001049123, Currency::where('code', 'XBT')->value('rate') + 0, 1e-16);
        $this->assertSame(0.0000001049123, Currency::where('code', 'XBT')->first()->rate);

        $this->assertSame('0.0000001049123', format_rate(0.0000001049123));
        $this->assertSame('44.639', format_rate('44.63900000'));
        $this->assertSame('1 250 000', format_rate(1250000, thousands: ' '));
        $this->assertSame('0', format_rate(0));
    }
}
