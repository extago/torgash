<?php

namespace Tests\Feature\Taxes;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Taxes\Models\Tax;
use Torgash\Taxes\TaxCalculator;
use Torgash\Taxes\TaxResolver;

/**
 * The arithmetic, in minor units. Every number below was worked out by hand
 * first — a test that only agrees with the implementation proves nothing about
 * money.
 */
class TaxCalculatorTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'TAX-1',
            'name' => ['en' => 'Taxed product'],
            'slug' => 'taxed-product',
            'price' => 100_00,
            'stock' => 100,
            'status' => 'active',
        ]);
    }

    public function test_an_included_tax_is_carved_out_of_the_price_and_does_not_change_it(): void
    {
        $this->tax('vat', 20, includes: true);

        $lines = $this->calculator()->forCart([$this->line(100_00, 1)]);

        // 100.00 gross at 20% is 83.33 net plus 16.67 tax, not 20.00.
        $this->assertSame(16_67, $lines[array_key_first($lines)]->amount);
        $this->assertTrue($lines[array_key_first($lines)]->included());
    }

    public function test_an_added_tax_is_a_share_of_the_price_on_top(): void
    {
        $this->tax('sales', 8.5, includes: false);

        $lines = $this->calculator()->forCart([$this->line(100_00, 2)]);

        // 8.5% of 200.00.
        $this->assertSame(17_00, $lines[array_key_first($lines)]->amount);
        $this->assertFalse($lines[array_key_first($lines)]->included());
    }

    public function test_taxes_sharing_a_priority_are_both_charged_on_the_original_price(): void
    {
        $this->tax('gst', 10, includes: false, priority: 0);
        $this->tax('pst', 10, includes: false, priority: 0);

        $lines = $this->calculator()->forCart([$this->line(100_00, 1)]);

        $this->assertSame([10_00, 10_00], array_map(fn ($line) => $line->amount, array_values($lines)));
    }

    public function test_a_higher_priority_is_charged_on_what_the_lower_one_already_raised(): void
    {
        $this->tax('first', 10, includes: false, priority: 0);
        $this->tax('second', 10, includes: false, priority: 1);

        $lines = array_values($this->calculator()->forCart([$this->line(100_00, 1)]));

        // 10.00 on 100.00, then 10% of 110.00 — tax on tax, which is exactly
        // what a different priority means and what the same priority avoids.
        $this->assertSame([10_00, 11_00], array_map(fn ($line) => $line->amount, $lines));
    }

    public function test_a_fixed_tax_is_charged_per_item(): void
    {
        $this->tax('duty', 0, includes: false, type: Tax::FIXED, amount: 1_50);

        $lines = $this->calculator()->forCart([$this->line(100_00, 3)]);

        $this->assertSame(4_50, $lines[array_key_first($lines)]->amount);
    }

    /**
     * Tax is owed on what the customer actually pays, so an order-level coupon
     * has to reach the lines before they are taxed.
     */
    public function test_an_order_discount_reduces_the_taxed_amount(): void
    {
        $this->tax('sales', 10, includes: false);

        $lines = $this->calculator()->forCart([$this->line(100_00, 2)], discount: 50_00);

        $this->assertSame(15_00, $lines[array_key_first($lines)]->amount);
    }

    public function test_shipping_is_taxed_only_when_the_tax_says_so(): void
    {
        $tax = $this->tax('sales', 10, includes: false);

        $withoutShippingTax = $this->calculator()->forCart([$this->line(100_00, 1)], shippingCost: 20_00);
        $this->assertSame(10_00, $withoutShippingTax[$tax->id]->amount);

        $tax->update(['applies_to_shipping' => true]);
        $this->refreshServices();

        $withShippingTax = $this->calculator()->forCart([$this->line(100_00, 1)], shippingCost: 20_00);
        $this->assertSame(12_00, $withShippingTax[$tax->id]->amount);
    }

    /**
     * Rounding per unit and per line are both legal somewhere and differ by a
     * cent on awkward quantities — which is exactly why it is a setting and
     * why the difference is pinned down here.
     */
    public function test_rounding_per_unit_and_per_line_differ_where_they_should(): void
    {
        $this->tax('sales', 10, includes: false);

        // 3 × 3.33 = 9.99; a tenth of that is 0.999 → 1.00 for the line,
        // but 0.33 per unit → 0.99.
        app(SettingsManager::class)->set('torgash.taxes', 'rounding', 'line');
        $perLine = $this->calculator()->forCart([$this->line(3_33, 3)]);
        $this->assertSame(1_00, $perLine[array_key_first($perLine)]->amount);

        app(SettingsManager::class)->set('torgash.taxes', 'rounding', 'unit');
        $this->refreshServices();
        $perUnit = $this->calculator()->forCart([$this->line(3_33, 3)]);
        $this->assertSame(99, $perUnit[array_key_first($perUnit)]->amount);
    }

    public function test_a_product_with_its_own_taxes_ignores_the_default_ones(): void
    {
        // Codes that cannot collide with the ones the module's migration seeds.
        $default = $this->tax('std-rate', 20, includes: true);
        $reduced = $this->tax('reduced-rate', 10, includes: true, byDefault: false);

        DB::table('m_taxes_product_tax')->insert([
            'product_id' => $this->product->id,
            'tax_id' => $reduced->id,
        ]);
        $this->refreshServices();

        $lines = $this->calculator()->forCart([$this->line(110_00, 1)]);

        $this->assertArrayNotHasKey($default->id, $lines);
        $this->assertSame(10_00, $lines[$reduced->id]->amount);
    }

    public function test_nothing_is_charged_when_no_tax_applies(): void
    {
        $this->assertSame([], $this->calculator()->forCart([$this->line(100_00, 1)]));
    }

    protected function tax(
        string $code,
        float $percent,
        bool $includes,
        int $priority = 0,
        string $type = Tax::PERCENT,
        int $amount = 0,
        bool $byDefault = true,
    ): Tax {
        $tax = Tax::create([
            'code' => $code,
            'name' => ['en' => $code],
            'rate_type' => $type,
            'rate_percent' => $type === Tax::PERCENT ? $percent : null,
            'rate_amount' => $type === Tax::FIXED ? $amount : null,
            'price_includes_tax' => $includes,
            'priority' => $priority,
            'applies_by_default' => $byDefault,
            'applies_to_shipping' => false,
            'enabled' => true,
            'position' => Tax::count(),
        ]);

        $this->refreshServices();

        return $tax;
    }

    /** @return array{product_id: int, unit_price: int, qty: int} */
    protected function line(int $unitPrice, int $qty): array
    {
        return ['product_id' => $this->product->id, 'unit_price' => $unitPrice, 'qty' => $qty];
    }

    protected function calculator(): TaxCalculator
    {
        return app(TaxCalculator::class);
    }

    /** The resolver memoises for the length of a request; a test changes the world mid-request. */
    protected function refreshServices(): void
    {
        $this->app->forgetInstance(TaxResolver::class);
        $this->app->forgetInstance(TaxCalculator::class);
    }
}
