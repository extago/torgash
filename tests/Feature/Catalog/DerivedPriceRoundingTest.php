<?php

namespace Tests\Feature\Catalog;

use App\Core\Support\Currencies;
use App\Models\Currency;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Pricing\Prices;

/**
 * A price typed in another currency is stored rounded to the places the
 * books currency is SHOWN with.
 *
 * A shop keeping dollars without cents showed €89 as "$100" on the product and
 * charged $100.28 at the till: the page rounded for display, the bill printed
 * the exact charge, and neither number was one anybody chose.
 */
class DerivedPriceRoundingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Currency::updateOrCreate(['code' => 'EUR'], ['symbol' => '€', 'rate' => 0.8875, 'decimals' => 2, 'enabled' => true]);
        app(Currencies::class)->flush();
    }

    protected function books(int $decimals): void
    {
        Currency::where('code', shop_currency())->firstOrFail()->update(['decimals' => $decimals]);
        app(Currencies::class)->flush();
    }

    public function test_with_cents_shown_the_derived_price_keeps_its_cents(): void
    {
        $this->books(2);

        $columns = app(Prices::class)->columns(['price' => '89'], 'EUR');

        $this->assertSame(8900, $columns['price_original']);
        $this->assertSame(10028, $columns['price']);
    }

    public function test_without_cents_shown_the_derived_price_is_a_whole_unit_and_the_cost_is_not_rounded(): void
    {
        $this->books(0);

        $columns = app(Prices::class)->columns(['price' => '89', 'list_price' => '111', 'cost_price' => '50'], 'EUR');

        $this->assertSame(10000, $columns['price']);
        $this->assertSame(12500, $columns['list_price']);
        // Nobody's shelf price: a margin must not move by a unit because of display.
        $this->assertSame(5634, $columns['cost_price']);
        $this->assertSame(10000, app(Prices::class)->row('89', 'EUR')['price']);
    }

    public function test_a_rate_change_rebases_to_the_same_rounding(): void
    {
        $this->books(0);
        $product = Product::create([
            'sku' => 'ROUND-1', 'name' => ['en' => 'Round'], 'slug' => 'round-1', 'stock' => 1, 'status' => 'active',
            ...app(Prices::class)->columns(['price' => '89'], 'EUR'),
        ]);

        Currency::where('code', 'EUR')->firstOrFail()->update(['rate' => 0.9]);

        $this->assertSame(9900, $product->fresh()->price);
    }

    public function test_showing_the_books_with_other_places_rebases_every_derived_price(): void
    {
        $this->books(2);
        $product = Product::create([
            'sku' => 'ROUND-2', 'name' => ['en' => 'Round'], 'slug' => 'round-2', 'stock' => 1, 'status' => 'active',
            ...app(Prices::class)->columns(['price' => '89'], 'EUR'),
        ]);
        $this->assertSame(10028, $product->fresh()->price);

        $this->books(0);

        $this->assertSame(10000, $product->fresh()->price);
    }
}
