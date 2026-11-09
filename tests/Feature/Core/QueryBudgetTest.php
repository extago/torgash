<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\PaymentMethod;

/**
 * A grid must cost the same number of queries whatever it holds.
 *
 * N+1 is invisible in a template — a card calling primaryImageUrl() and
 * effectivePrice() lazy-loads per row, and the page still renders perfectly.
 * The scope that prevents it (withCardData) is one call that anyone can drop
 * while refactoring, so the guard is a count, not a review.
 */
class QueryBudgetTest extends TestCase
{
    public function test_the_catalogue_grid_does_not_cost_more_queries_as_it_grows(): void
    {
        $this->makeProducts(3);

        // One request first, unmeasured: singletons that memo per request
        // (Currencies, Labels, PriceTypes, the settings cache) are filled once
        // per PROCESS here, because tests reuse one application across
        // requests. Without this the first page always looks the most expensive.
        $this->get('http://localhost/catalog')->assertOk();

        $few = $this->queriesFor('http://localhost/catalog');

        $this->makeProducts(9, offset: 3);
        $many = $this->queriesFor('http://localhost/catalog');

        $this->assertSame(
            $few,
            $many,
            "the catalogue ran {$many} queries for 12 products but {$few} for 3 — something loads per row",
        );
    }

    /**
     * The home page is product BLOCKS now, each its own query — so the budget
     * is per block, and must not grow with what the blocks hold.
     *
     * The product on sale is the NEWEST one both times, so it is inside the
     * first tab in both measurements: a module priming the page's set (the
     * reviews' averages) rightly skips ids it already holds, and a sale product
     * outside the first tab in one measurement but not the other would be that
     * memo, counted as a leak.
     */
    public function test_the_home_page_blocks_do_not_cost_more_queries_as_the_catalogue_grows(): void
    {
        $this->makeProducts(3);
        Product::where('sku', 'BUDGET-3')->update(['list_price' => 99_00]);

        $this->get('http://localhost/')->assertOk();

        $few = $this->queriesFor('http://localhost/');

        $this->makeProducts(9, offset: 3);
        Product::where('sku', 'BUDGET-3')->update(['list_price' => null]);
        Product::where('sku', 'BUDGET-12')->update(['list_price' => 99_00]);
        $many = $this->queriesFor('http://localhost/');

        $this->assertSame($few, $many, "the home page ran {$many} queries with 12 products but {$few} with 3 — a block loads something per card");
    }

    public function test_the_cart_does_not_cost_more_queries_as_it_fills(): void
    {
        $this->makeProducts(4);

        $this->post('http://localhost/cart/add/test-product-1', ['qty' => 1]);

        $this->get('http://localhost/cart')->assertOk(); // warm-up, see above
        $one = $this->queriesFor('http://localhost/cart');

        foreach ([2, 3, 4] as $i) {
            $this->post("http://localhost/cart/add/test-product-{$i}", ['qty' => 1]);
        }

        $four = $this->queriesFor('http://localhost/cart');

        $this->assertSame($one, $four, 'the cart loads something per line');
    }

    /** A quote asks for the basket's items about twenty times; the products are read once. */
    public function test_a_checkout_quote_reads_the_baskets_products_once(): void
    {
        Mail::fake();
        PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => -1]);
        $this->makeProducts(3);

        foreach ([1, 2, 3] as $i) {
            $this->post("http://localhost/cart/add/test-product-{$i}", ['qty' => 1]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->post('http://localhost/checkout/quote', ['city' => 'Town'])->assertOk();
        $queries = array_column(DB::getRawQueryLog(), 'raw_query');
        DB::disableQueryLog();

        $reads = array_filter($queries, fn (string $sql) => str_starts_with($sql, 'select * from "m_catalog_products" where "id" in'));

        $this->assertCount(1, $reads, 'the basket read its products again: '.count($queries).' queries in all');
    }

    protected function makeProducts(int $count, int $offset = 0): void
    {
        for ($i = $offset + 1; $i <= $offset + $count; $i++) {
            Product::create([
                'sku' => "BUDGET-{$i}",
                'name' => ['en' => "Budget product {$i}"],
                'slug' => "test-product-{$i}",
                'price' => 10_00 + $i,
                'stock' => 5,
                'status' => 'active',
            ]);
        }
    }

    protected function queriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get($url)->assertOk();

        $count = count(DB::getRawQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
