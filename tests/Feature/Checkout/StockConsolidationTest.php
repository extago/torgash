<?php

namespace Tests\Feature\Checkout;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\OrderStock;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * An order should leave one warehouse when one warehouse can send it.
 *
 * Not an optimisation: a line filled here and a line filled there is two
 * pickings and, where the warehouses ship separately, two parcels — worse for
 * the shop and for the customer than sending everything from a warehouse
 * further down the priority list. What the shop owner's priority order still
 * decides is which warehouse wins among those that CAN cover the order.
 *
 * The rule is a preference and never a refusal: anything the chosen warehouse
 * cannot cover falls through to the old priority walk.
 */
class StockConsolidationTest extends TestCase
{
    protected int $near;

    protected int $far;

    protected Product $a;

    protected Product $b;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->near = $this->warehouse('Near', sort: 0);
        $this->far = $this->warehouse('Far', sort: 10);

        $this->a = $this->product('CON-A', 'con-a');
        $this->b = $this->product('CON-B', 'con-b');
    }

    public function test_the_order_is_taken_from_one_warehouse_that_can_send_it_all(): void
    {
        // Priority says "Near first", but Near has none of B. Filling greedily
        // would take A from Near and B from Far — one order, two parcels.
        $this->stock($this->a, [$this->near => 5, $this->far => 5]);
        $this->stock($this->b, [$this->near => 0, $this->far => 5]);

        $items = $this->deduct([[$this->a, 2], [$this->b, 1]]);

        $this->assertSame([$this->far => -2], $this->breakdown($items, $this->a));
        $this->assertSame([$this->far => -1], $this->breakdown($items, $this->b));
    }

    public function test_priority_still_decides_between_warehouses_that_can_both_send_it(): void
    {
        $this->stock($this->a, [$this->near => 5, $this->far => 5]);
        $this->stock($this->b, [$this->near => 5, $this->far => 5]);

        $items = $this->deduct([[$this->a, 2], [$this->b, 1]]);

        // The owner's order is the tie-breaker, not a thing consolidation
        // overrides.
        $this->assertSame([$this->near => -2], $this->breakdown($items, $this->a));
        $this->assertSame([$this->near => -1], $this->breakdown($items, $this->b));
    }

    public function test_when_nobody_can_send_it_all_the_old_priority_walk_still_fills_it(): void
    {
        // Neither warehouse has both. Consolidation must not turn into a
        // refusal — the sale goes through exactly as before.
        $this->stock($this->a, [$this->near => 5, $this->far => 0]);
        $this->stock($this->b, [$this->near => 0, $this->far => 5]);

        $items = $this->deduct([[$this->a, 2], [$this->b, 1]]);

        $this->assertSame([$this->near => -2], $this->breakdown($items, $this->a));
        $this->assertSame([$this->far => -1], $this->breakdown($items, $this->b));
    }

    public function test_a_preferred_warehouse_that_runs_short_still_falls_through(): void
    {
        // Far can cover the ORDER (it has some of both) but not this quantity.
        $this->stock($this->a, [$this->near => 5, $this->far => 1]);
        $this->stock($this->b, [$this->near => 0, $this->far => 5]);

        $items = $this->deduct([[$this->a, 3], [$this->b, 1]]);

        $this->assertSame(3, -array_sum($this->breakdown($items, $this->a)), 'all three units come out somewhere');
    }

    public function test_placing_an_order_consolidates_too(): void
    {
        $this->stock($this->a, [$this->near => 5, $this->far => 5]);
        $this->stock($this->b, [$this->near => 0, $this->far => 5]);

        $this->post('http://localhost/cart/add/con-a', ['qty' => 1])->assertOk();
        $this->post('http://localhost/cart/add/con-b', ['qty' => 1])->assertOk();
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ])->assertRedirect();

        // The checkout used to keep its own copy of the deduction loop; this is
        // what proves it now goes through the same OrderStock as the admin.
        $items = Order::latest('id')->firstOrFail()->items;

        $this->assertSame([$this->far => -1], $this->breakdown($items, $this->a));
        $this->assertSame([$this->far => -1], $this->breakdown($items, $this->b));
    }

    // ---------------------------------------------------------------- helpers

    /** @param  list<array{0: Product, 1: int}>  $lines */
    protected function deduct(array $lines): array
    {
        $items = array_map(fn ($line) => [
            'product_id' => $line[0]->id,
            'sku' => $line[0]->sku,
            'name' => $line[0]->t('name'),
            'qty' => $line[1],
            'unit_price' => 10_00,
            'line_total' => 10_00 * $line[1],
        ], $lines);

        return app(OrderStock::class)->deductAll($items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<int, int>
     */
    protected function breakdown(array $items, Product $product): array
    {
        foreach ($items as $item) {
            if ((int) $item['product_id'] === $product->id) {
                $out = [];
                foreach ((array) ($item['warehouses'] ?? []) as $id => $delta) {
                    $out[(int) $id] = (int) $delta;
                }

                return $out;
            }
        }

        return [];
    }

    protected function warehouse(string $name, int $sort): int
    {
        return (int) DB::table('m_warehouses')->insertGetId([
            'name' => json_encode(['en' => $name]),
            'sort' => $sort,
            'enabled' => true,
            'is_default' => $sort === 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function product(string $sku, string $slug): Product
    {
        return Product::create([
            'sku' => $sku,
            'name' => ['en' => $sku],
            'slug' => $slug,
            'price' => 10_00,
            'stock' => 0,
            'status' => 'active',
        ]);
    }

    /** @param  array<int, int>  $byWarehouse */
    protected function stock(Product $product, array $byWarehouse): void
    {
        foreach ($byWarehouse as $warehouseId => $qty) {
            DB::table('m_warehouse_product_stock')->insert([
                'warehouse_id' => $warehouseId,
                'product_id' => $product->id,
                'qty' => $qty,
            ]);
        }

        $product->update(['stock' => array_sum($byWarehouse)]);
    }
}
