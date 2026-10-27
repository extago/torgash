<?php

namespace Tests\Feature\Cart;

use Tests\TestCase;
use Torgash\Cart\CartManager;
use Torgash\Cart\Lines;
use Torgash\Cart\Models\Cart;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\OrderLines;
use Torgash\Checkout\Orders\OrderStock;

/**
 * A cart line is no longer "a product and a number" (PLAN §33.3) — it is a
 * line with a KEY, a configuration, and parts that the configuration adds.
 *
 * What is pinned here is mostly that NOTHING changed for a plain product: its
 * key is its product id, every caller that addressed a line by product id
 * still addresses the line it meant, and a cart written before this reads back
 * as the same basket. On top of that: two builds of one product are two lines,
 * the same build is one line of two, and a part that is itself a PRODUCT
 * becomes its own line of the ORDER while a service folds into the price of
 * the line it belongs to — with the two lists adding up to the same money.
 */
class CartLinesTest extends TestCase
{
    protected Product $server;

    protected Product $cpu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Product::create([
            'sku' => 'SRV-1', 'name' => ['en' => 'Server'], 'slug' => 'server',
            'price' => 1000_00, 'stock' => 10, 'status' => 'active', 'weight' => 8000,
        ]);

        $this->cpu = Product::create([
            'sku' => 'CPU-1', 'name' => ['en' => 'Xeon E5-2407'], 'slug' => 'xeon-e5-2407',
            'price' => 170_00, 'stock' => 20, 'status' => 'active', 'weight' => 200,
        ]);
    }

    public function test_a_plain_line_is_still_addressed_by_its_product_id(): void
    {
        $cart = app(CartManager::class);

        $this->assertSame((string) $this->server->id, $cart->add($this->server->id, 2));

        $cart->update($this->server->id, 3);
        $this->assertSame(3, $cart->items()->first()->qty);

        $cart->remove($this->server->id);
        $this->assertSame(0, $cart->count());
    }

    /** A basket from before this change is the same basket after it. */
    public function test_the_old_map_of_product_to_quantity_still_reads(): void
    {
        $row = Cart::create([
            'token' => Cart::newToken(),
            'items' => [$this->server->id => 2, $this->cpu->id => 1],
            'last_active_at' => now(),
        ]);

        $cart = app(CartManager::class)->useToken($row->token);

        $this->assertSame(3, $cart->count());
        $this->assertSame(2170_00, $cart->total());
        $this->assertSame([(string) $this->server->id, (string) $this->cpu->id], $cart->items()->pluck('key')->all());
    }

    public function test_two_builds_of_one_product_are_two_lines_and_the_same_build_is_one(): void
    {
        $cart = app(CartManager::class);

        $one = $cart->add($this->server->id, 1, ['cpu' => 'xeon']);
        $two = $cart->add($this->server->id, 1, ['cpu' => 'none']);
        $again = $cart->add($this->server->id, 1, ['cpu' => 'xeon']);

        $this->assertNotSame($one, $two);
        $this->assertSame($one, $again);
        $this->assertCount(2, $cart->raw());
        $this->assertSame(2, $cart->items()->firstWhere('key', $one)->qty);

        // The same answers in another order are the same build.
        $this->assertSame(
            Lines::key($this->server->id, ['cpu' => 'xeon', 'ram' => '64']),
            Lines::key($this->server->id, ['ram' => '64', 'cpu' => 'xeon']),
        );
    }

    /**
     * Whoever understands the configuration prices it; cart only adds up. Two
     * scales, because they answer different questions: a processor is per
     * server, an installation is once for the line however many you buy.
     */
    public function test_parts_price_and_weigh_the_line(): void
    {
        hook_listen('cart.line.parts', fn (array $parts, array $context) => [
            ['code' => 'cpu', 'label' => 'CPU: Xeon', 'qty' => 2, 'amount' => 170_00, 'weight' => 200, 'product_id' => $this->cpu->id],
            ['code' => 'setup', 'label' => 'Installation', 'qty' => 1, 'amount' => 50_00, 'per_unit' => false],
        ]);

        $cart = app(CartManager::class);
        $cart->add($this->server->id, 2, ['cpu' => 'xeon']);
        $line = $cart->items()->first();

        // 1000 + two processors at 170
        $this->assertSame(1340_00, $line->unit_price);
        $this->assertSame(50_00, $line->extra_total);
        // two servers at 1340, plus one installation
        $this->assertSame(2730_00, $line->line_total);
        $this->assertSame(2730_00, $cart->total());
        // 2 × (8000 + 2 × 200)
        $this->assertSame(16800, $cart->weight());
    }

    public function test_a_product_part_becomes_its_own_order_line_and_a_service_folds_into_the_price(): void
    {
        hook_listen('cart.line.parts', fn (array $parts) => [
            ['code' => 'cpu', 'label' => 'CPU: Xeon', 'qty' => 2, 'amount' => 170_00, 'product_id' => $this->cpu->id, 'sku' => 'CPU-1', 'name' => 'Xeon E5-2407'],
            ['code' => 'warranty', 'label' => 'Extended warranty', 'qty' => 1, 'amount' => 60_00],
        ]);

        $cart = app(CartManager::class);
        $cart->add($this->server->id, 2, ['cpu' => 'xeon']);
        $lines = OrderLines::fromBasket($cart->items());

        $this->assertCount(2, $lines);

        // The parent keeps the warranty inside its unit price and says so.
        $this->assertSame($this->server->id, $lines[0]['product_id']);
        $this->assertSame(1060_00, $lines[0]['unit_price']);
        $this->assertSame([['label' => 'Extended warranty', 'qty' => 1, 'amount' => 60_00]], $lines[0]['options']);

        // The processors are goods, so they are a line of their own.
        $this->assertSame($this->cpu->id, $lines[1]['product_id']);
        $this->assertSame($lines[0]['key'], $lines[1]['parent_key']);
        $this->assertSame(4, $lines[1]['qty']);
        $this->assertSame(680_00, $lines[1]['line_total']);

        // Two ways of describing one purchase, one amount of money.
        $this->assertSame($cart->total(), array_sum(array_column($lines, 'line_total')));
    }

    /** A service charged once for the line cannot hide inside a unit price. */
    public function test_a_once_per_line_service_gets_a_line_with_no_product(): void
    {
        hook_listen('cart.line.parts', fn (array $parts) => [
            ['code' => 'setup', 'label' => 'Installation', 'qty' => 1, 'amount' => 50_00, 'per_unit' => false],
        ]);

        $cart = app(CartManager::class);
        $cart->add($this->server->id, 3, ['setup' => 'yes']);
        $lines = OrderLines::fromBasket($cart->items());

        $this->assertCount(2, $lines);
        $this->assertNull($lines[1]['product_id']);
        $this->assertSame(1, $lines[1]['qty']);
        $this->assertSame(50_00, $lines[1]['line_total']);
        $this->assertSame($cart->total(), array_sum(array_column($lines, 'line_total')));
    }

    /**
     * The latent bug configurations would have exposed: an order can hold the
     * same product on two lines, and the warehouse has to move for each of
     * them rather than for whichever one came last.
     */
    public function test_two_lines_of_one_product_move_stock_independently(): void
    {
        $order = Order::create([
            'order_number' => 'TG-LINES-1',
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test',
            'address_line' => 'Somewhere', 'city' => 'Kyiv',
            'currency_code' => shop_currency(),
            'subtotal' => 0, 'total' => 0, 'status' => 'new', 'items' => [],
            'stock_deducted' => true,
        ]);

        $before = [
            ['key' => (string) $this->cpu->id, 'product_id' => $this->cpu->id, 'qty' => 1, 'unit_price' => 170_00, 'line_total' => 170_00],
            ['key' => 'srv:cpu', 'parent_key' => 'srv', 'product_id' => $this->cpu->id, 'qty' => 2, 'unit_price' => 170_00, 'line_total' => 340_00],
        ];
        $after = $before;
        $after[1]['qty'] = 5;

        $stockBefore = $this->cpu->fresh()->stock;
        $lines = app(OrderStock::class)->apply($order, $before, $after);

        $this->assertCount(2, $lines);
        $this->assertSame($stockBefore - 3, $this->cpu->fresh()->stock);
        $this->assertSame(1, $lines[0]['qty']);
        $this->assertSame(5, $lines[1]['qty']);
    }

    public function test_printing_puts_the_parts_under_what_they_were_bought_for(): void
    {
        $tree = OrderLines::tree([
            ['key' => 'a', 'product_id' => 1, 'qty' => 1],
            ['key' => 'a:cpu', 'parent_key' => 'a', 'product_id' => 2, 'qty' => 2],
            ['key' => 'b', 'product_id' => 3, 'qty' => 1],
            // A part whose parent was deleted in the admin: still charged for,
            // so still printed.
            ['key' => 'gone:x', 'parent_key' => 'gone', 'product_id' => 4, 'qty' => 1],
        ]);

        $this->assertCount(3, $tree);
        $this->assertSame('a', $tree[0]['line']['key']);
        $this->assertSame(['a:cpu'], array_column($tree[0]['children'], 'key'));
        $this->assertSame([], $tree[1]['children']);
        $this->assertSame('gone:x', $tree[2]['line']['key']);
    }
}
