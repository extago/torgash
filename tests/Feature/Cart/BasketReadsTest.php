<?php

namespace Tests\Feature\Cart;

use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Cart\CartManager;
use Torgash\Cart\Models\Cart;
use Torgash\Catalog\Models\Product;

/**
 * A checkout asks for the basket's items about twenty times in one request,
 * so the ROWS behind them are read once — for as long as nothing is written.
 * What is pinned here is the second half: a basket that went on showing a
 * price or a shelf that has just changed would be worse than a slow one.
 */
class BasketReadsTest extends TestCase
{
    protected Product $kettle;

    protected Product $teapot;

    protected CartManager $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kettle = Product::create(['sku' => 'BR-1', 'name' => ['en' => 'Kettle'], 'slug' => 'br-kettle', 'price' => 100_00, 'stock' => 5, 'status' => 'active']);
        $this->teapot = Product::create(['sku' => 'BR-2', 'name' => ['en' => 'Teapot'], 'slug' => 'br-teapot', 'price' => 50_00, 'stock' => 5, 'status' => 'active']);

        $this->cart = app(CartManager::class);
        $this->cart->add($this->kettle->id);
        $this->cart->add($this->teapot->id, 2);
    }

    /** How many times `$ask` read the products. */
    protected function reads(\Closure $ask): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $ask();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return count(array_filter($queries, fn (string $sql) => str_starts_with($sql, 'select * from "m_catalog_products" where "id" in')));
    }

    public function test_the_products_are_read_once_however_often_the_basket_is_asked(): void
    {
        $reads = $this->reads(function () {
            $this->cart->items();
            $this->cart->total();
            $this->cart->needsDelivery();
            $this->cart->hasProblems();
        });

        $this->assertSame(1, $reads);
    }

    public function test_a_price_written_by_anybody_is_read_again(): void
    {
        $this->assertSame(200_00, $this->cart->total());

        DB::table('m_catalog_products')->where('id', $this->kettle->id)->update(['price' => 70_00]);

        $this->assertSame(170_00, $this->cart->total(), 'a plain query, nothing announced');
    }

    public function test_a_shelf_emptied_since_is_a_problem_at_once(): void
    {
        $this->assertFalse($this->cart->hasProblems());

        $this->teapot->fresh()->adjustStock(-5);

        $this->assertTrue($this->cart->hasProblems());
    }

    public function test_a_line_added_or_changed_is_counted_at_once(): void
    {
        $this->assertSame(200_00, $this->cart->total());

        $this->cart->update($this->teapot->id, 1);
        $this->assertSame(150_00, $this->cart->total());

        $this->cart->remove($this->kettle->id);
        $this->assertSame(50_00, $this->cart->total());
    }

    /** The visitor's own price is worked out on every call — only the rows are kept. */
    public function test_a_price_for_the_visitor_is_not_remembered_with_the_rows(): void
    {
        $group = CustomerGroup::create(['name' => ['en' => 'Friends'], 'discount_percent' => 50]);
        $friend = User::factory()->create(['customer_group_id' => $group->id]);

        $this->assertSame(200_00, $this->cart->total());

        $this->actingAs($friend);

        $total = 0;
        $reads = $this->reads(function () use (&$total) {
            $total = $this->cart->total();
        });

        $this->assertSame(100_00, $total);
        $this->assertSame(0, $reads, 'the very rows read for the guest');
    }

    public function test_another_basket_is_read_for_what_it_holds(): void
    {
        $this->assertSame(200_00, $this->cart->total());

        $other = Cart::create(['token' => Cart::newToken(), 'items' => [['key' => (string) $this->kettle->id, 'product_id' => $this->kettle->id, 'qty' => 3, 'config' => []]], 'last_active_at' => now()]);

        $this->assertSame(300_00, $this->cart->useToken($other->token)->total());
    }
}
