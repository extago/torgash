<?php

namespace Tests\Feature\Checkout;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Orders\OrderStatusChanger;

/**
 * A status's `inventory_action` has three values, and "none" is the one that
 * was a lie: the status form calls it "do not touch stock", but
 * OrderStatusChanger treated it as a synonym for "increase" and put the units
 * back. Now it is genuinely neutral — an order moved into a "none" status keeps
 * whatever stock state it already had.
 */
class StatusInventoryActionTest extends TestCase
{
    private Product $product;

    private OrderStatusChanger $changer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->product = Product::create([
            'sku' => 'INV-1',
            'name' => ['en' => 'Widget'],
            'slug' => 'inv-widget',
            'price' => 10_00,
            'stock' => 5,
            'status' => 'active',
        ]);

        $this->changer = app(OrderStatusChanger::class);

        OrderStatus::firstOrCreate(
            ['code' => 'on_hold'],
            ['name' => ['en' => 'On hold'], 'color' => '#cccccc', 'position' => 20, 'inventory_action' => 'none'],
        );
    }

    public function test_moving_a_committed_order_to_a_none_status_leaves_stock_and_flag_alone(): void
    {
        // It is already committed: two units are off the shelf and the order
        // knows it.
        $this->product->decrement('stock', 2);
        $order = $this->order('open', deducted: true);

        $this->changer->change($order, OrderStatus::findOrFail('on_hold'));

        $this->assertSame('on_hold', $order->fresh()->status);
        $this->assertTrue($order->fresh()->stock_deducted, 'the flag must not flip');
        $this->assertSame(3, $this->product->fresh()->stock, 'stock must not be restored');
    }

    public function test_a_none_status_does_not_commit_stock_either(): void
    {
        $order = $this->order('awaiting_payment', deducted: false);

        $this->changer->change($order, OrderStatus::findOrFail('on_hold'));

        $this->assertFalse($order->fresh()->stock_deducted);
        $this->assertSame(5, $this->product->fresh()->stock);
    }

    public function test_increase_still_restores_a_committed_order(): void
    {
        $this->product->decrement('stock', 2);
        $order = $this->order('open', deducted: true);

        $this->changer->change($order, OrderStatus::findOrFail('cancelled'));

        $this->assertFalse($order->fresh()->stock_deducted);
        $this->assertSame(5, $this->product->fresh()->stock);
    }

    public function test_decrease_still_commits_a_fresh_order(): void
    {
        $order = $this->order('awaiting_payment', deducted: false);

        $this->changer->change($order, OrderStatus::findOrFail('open'));

        $this->assertTrue($order->fresh()->stock_deducted);
        $this->assertSame(3, $this->product->fresh()->stock);
    }

    public function test_passing_through_a_none_status_restores_exactly_once(): void
    {
        $this->product->decrement('stock', 2);
        $order = $this->order('open', deducted: true);

        $this->changer->change($order, OrderStatus::findOrFail('on_hold'));
        $this->assertSame(3, $this->product->fresh()->stock);

        $this->changer->change($order->fresh(), OrderStatus::findOrFail('cancelled'));
        $this->assertSame(5, $this->product->fresh()->stock);
        $this->assertFalse($order->fresh()->stock_deducted);
    }

    private function order(string $status, bool $deducted): Order
    {
        return Order::create([
            'customer_name' => 'Guest',
            'subtotal' => 20_00,
            'total' => 20_00,
            'items' => [[
                'product_id' => $this->product->id,
                'qty' => 2,
                'price' => 10_00,
                'name' => 'Widget',
            ]],
            'status' => $status,
            'stock_deducted' => $deducted,
        ]);
    }
}
