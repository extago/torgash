<?php

namespace Tests\Feature\Checkout;

use App\Models\Currency;
use Tests\Fixtures\Checkout\FakeCarrier;
use Tests\TestCase;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Money;
use Torgash\Shipping\ProviderOffer;
use Torgash\Shipping\ShippingOptions;
use Torgash\Shipping\ZoneMatcher;

/**
 * A delivery priced in a currency the catalogue is not kept in.
 *
 * The ordinary case: the shop's prices are in dollars because that is what its
 * suppliers quote, while the courier's tariff and Nova Poshta's invoice are in
 * hryvnia. Writing 45.50 into a dollar field and hoping the rate never moves is
 * how a delivery price drifts quietly.
 *
 * Everything downstream — the order total, the taxes snapshot, format_price —
 * speaks base minor units, so the conversion happens once, at the moment the
 * amount becomes a price, and nothing else in the engine learns about it.
 */
class ShippingCurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 41 hryvnia to the dollar, whole hryvnia shown — the shop's own table.
        Currency::updateOrCreate(['code' => 'UAH'], [
            'symbol' => '₴', 'rate' => 41, 'decimals' => 0, 'enabled' => true,
        ]);
        app(\App\Core\Support\Currencies::class)->flush();
    }

    public function test_a_rate_written_in_another_currency_is_charged_in_the_base_one(): void
    {
        // 82 ₴ at 41 to the dollar is 2.00 $.
        $method = $this->methodWithRate(82, currency: 'UAH');

        $this->assertSame(2_00, $this->costOf($method));
    }

    public function test_a_rate_with_no_currency_is_untouched(): void
    {
        $method = $this->methodWithRate(2_00);

        $this->assertSame(2_00, $this->costOf($method));
    }

    public function test_free_above_is_compared_in_the_shops_money_not_the_rates(): void
    {
        // Free above 4100 ₴ = 100.00 $; the cart is 100.00 $, so it is free —
        // comparing 4100 against 10000 minor dollars would have charged it.
        $method = $this->methodWithRate(82, currency: 'UAH', freeOver: 4100);

        $this->assertSame(0, $this->costOf($method, subtotal: 100_00));
        $this->assertSame(2_00, $this->costOf($method, subtotal: 99_00));
    }

    public function test_a_percentage_band_stays_a_percentage_of_the_order(): void
    {
        // A percent is taken from the order total, which is already the shop's
        // money: converting it a second time would inflate it by the exchange
        // rate. 10% of 100.00 $ is 10.00 $, plus the 82 ₴ base = 12.00 $.
        $method = $this->methodWithRate(82, currency: 'UAH');
        $method->rates()->first()->rows()->create([
            'axis' => 'C', 'range_from' => 0, 'range_to' => null,
            'value_type' => 'percent', 'value_percent' => 10, 'per_unit' => false,
        ]);

        $this->assertSame(12_00, $this->costOf($method, subtotal: 100_00));
    }

    public function test_a_carrier_may_answer_in_its_own_currency(): void
    {
        FakeCarrier::reset();
        hook_listen('shipping.providers', fn (array $p) => $p + ['fake' => FakeCarrier::class]);
        FakeCarrier::$offers = [new ProviderOffer(key: 'branch', label: 'to branch', cost: 205, currency: 'UAH')];

        $method = $this->methodWithRate(0);
        $method->update(['provider' => 'fake', 'price_mode' => 'carrier']);

        // 205 ₴ = 5.00 $.
        $option = app(ShippingOptions::class)
            ->for(new Location(country: 'UA'), new Package(subtotal: 100_00))
            ->firstWhere('method.id', $method->id);

        $this->assertSame(5_00, $option->cost);
    }

    public function test_minor_units_follow_the_currencys_own_decimals(): void
    {
        // Hryvnia is shown whole here, dollars with cents: 45.50 ₴ rounds to
        // 46, 45.50 $ keeps its half.
        $this->assertSame(46, Money::toMinor('45.50', 'UAH'));
        $this->assertSame(4550, Money::toMinor('45.50', 'USD'));
        $this->assertSame('46', Money::toMajor(46, 'UAH'));
    }

    public function test_a_currency_that_no_longer_exists_does_not_invent_a_rate(): void
    {
        // The row survived its currency being deleted. Reading the number as
        // base money is at least the right order of magnitude; guessing a rate
        // would not be.
        $this->assertSame(500, Money::toBase(500, 'XXX'));
    }

    protected function methodWithRate(int $baseRate, ?string $currency = null, ?int $freeOver = null): ShippingMethod
    {
        static $n = 0;
        $method = ShippingMethod::create([
            'name' => ['en' => 'Currency method '.(++$n)], 'position' => 70 + $n, 'enabled' => true,
        ]);
        $method->rates()->create([
            'zone_id' => (new ZoneMatcher)->fallback()->id,
            'base_rate' => $baseRate,
            'free_over' => $freeOver,
            'currency' => $currency,
            'enabled' => true,
        ]);

        return $method;
    }

    protected function costOf(ShippingMethod $method, int $subtotal = 10_00): int
    {
        return app(ShippingOptions::class)
            ->for(new Location, new Package(subtotal: $subtotal))
            ->firstWhere('method.id', $method->id)
            ->cost;
    }
}
