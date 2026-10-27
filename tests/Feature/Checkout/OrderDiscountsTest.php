<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Tests\TestCase;
use Torgash\Checkout\Models\Coupon;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Orders\OrderDiscounts;
use Torgash\Checkout\Orders\OrderTotals;

/**
 * A discount on a placed order is TERMS, recomputed from what it holds — the
 * coupon's percent follows an edit of the contents — and a manager can put a
 * forgotten coupon on it, or a discount of their own with a reason.
 */
class OrderDiscountsTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    protected function order(array $attributes = []): Order
    {
        return Order::create($attributes + [
            'order_number' => 'TG-DSC'.random_int(1000, 9999),
            'customer_name' => 'Buyer', 'email' => 'b@example.test',
            'currency_code' => 'USD', 'status' => 'new', 'stock_deducted' => false,
            'subtotal' => 300_00, 'shipping_cost' => 0, 'total' => 300_00, 'discount_amount' => 0,
            'items' => [['key' => 'a', 'sku' => 'A', 'name' => 'Laptop', 'qty' => 3, 'unit_price' => 100_00, 'line_total' => 300_00]],
        ])->fresh();
    }

    public function test_a_percent_coupon_follows_the_orders_contents(): void
    {
        $order = $this->order([
            'coupon_code' => 'TEN', 'discount_amount' => 30_00, 'total' => 270_00,
            'coupon_terms' => ['type' => 'percent', 'value' => 10, 'min_subtotal' => null, 'excluded' => 0],
        ]);

        $items = $order->items;
        $items[0]['qty'] = 2;
        $items[0]['line_total'] = 200_00;
        app(OrderTotals::class)->recalculate($order, $items);

        $this->assertSame(20_00, $order->fresh()->discount_amount);
        $this->assertSame(180_00, $order->fresh()->total);
    }

    public function test_goods_added_after_placement_are_outside_the_coupon_until_a_manager_puts_it_on_again(): void
    {
        // PLAN §40.10/3: the coupon was agreed on three laptops.
        $order = $this->order([
            'coupon_code' => 'TEN', 'discount_amount' => 30_00, 'total' => 270_00,
            'coupon_terms' => ['type' => 'percent', 'value' => 10, 'min_subtotal' => null, 'excluded' => 0, 'lines' => ['a' => 3]],
        ]);
        Coupon::create(['code' => 'TEN', 'type' => 'percent', 'value' => 10, 'enabled' => true]);

        // The customer rings about screws, and wants a fourth laptop too.
        $items = $order->items;
        $items[0]['qty'] = 4;
        $items[0]['line_total'] = 400_00;
        $items[] = ['key' => 'screws', 'sku' => 'S', 'name' => 'Screws', 'qty' => 1, 'unit_price' => 50_00, 'line_total' => 50_00];
        app(OrderTotals::class)->recalculate($order, $items);
        $this->assertSame(30_00, $order->fresh()->discount_amount, 'what was added pays full price');

        // One laptop fewer than agreed: its share of the base leaves with it.
        $order = $order->fresh();
        $items = $order->items;
        $items[0]['qty'] = 2;
        $items[0]['line_total'] = 200_00;
        app(OrderTotals::class)->recalculate($order, $items);
        $this->assertSame(20_00, $order->fresh()->discount_amount);

        // Put on by hand, it covers everything the order holds now.
        $this->actingAs($this->admin)
            ->post("http://localhost/admin/orders/{$order->order_number}/coupon", ['code' => 'TEN'])
            ->assertOk();
        $this->assertSame(25_00, $order->fresh()->discount_amount);
    }

    public function test_what_the_coupon_did_not_reach_at_checkout_stays_out(): void
    {
        // 50.00 of the basket was a kit's goods the coupon did not discount.
        $order = $this->order([
            'coupon_code' => 'TEN', 'discount_amount' => 25_00,
            'coupon_terms' => ['type' => 'percent', 'value' => 10, 'min_subtotal' => null, 'excluded' => 50_00],
        ]);

        app(OrderTotals::class)->recalculate($order);

        $this->assertSame(25_00, $order->fresh()->discount_amount);
    }

    public function test_an_order_placed_before_terms_keeps_its_sum(): void
    {
        $order = $this->order(['coupon_code' => 'OLD', 'discount_amount' => 40_00]);

        app(OrderTotals::class)->recalculate($order);

        $this->assertSame(40_00, $order->fresh()->discount_amount);
    }

    public function test_a_manager_applies_a_forgotten_coupon_and_it_is_counted(): void
    {
        $coupon = Coupon::create(['code' => 'FORGOT', 'type' => 'percent', 'value' => 10, 'enabled' => true, 'expires_at' => now()->subDay()]);
        $order = $this->order();

        $this->actingAs($this->admin)
            ->post("http://localhost/admin/orders/{$order->order_number}/coupon", ['code' => ' forgot '])
            ->assertOk()
            ->assertSee('FORGOT');

        $order->refresh();
        $this->assertSame('FORGOT', $order->coupon_code);
        $this->assertSame(30_00, $order->discount_amount);
        $this->assertSame(270_00, $order->total);
        $this->assertSame(1, $coupon->refresh()->used_count);
        // Applied although expired: the manager decided, and the history says so.
        $this->assertStringContainsString('expired', OrderLog::where('order_id', $order->id)->latest('id')->first()->text());

        $this->delete("http://localhost/admin/orders/{$order->order_number}/coupon")->assertOk();
        $this->assertNull($order->fresh()->coupon_code);
        $this->assertSame(0, $order->fresh()->discount_amount);
        $this->assertSame(0, $coupon->refresh()->used_count);
    }

    public function test_an_unknown_or_disabled_coupon_is_refused_inside_the_card(): void
    {
        Coupon::create(['code' => 'OFF', 'type' => 'percent', 'value' => 10, 'enabled' => false]);
        $order = $this->order();

        $this->actingAs($this->admin)
            ->post("http://localhost/admin/orders/{$order->order_number}/coupon", ['code' => 'OFF'])
            ->assertOk()
            ->assertSee('No enabled coupon with that code.');

        $this->assertNull($order->fresh()->coupon_code);
    }

    public function test_the_managers_discount_needs_a_reason_and_comes_after_the_coupon(): void
    {
        $order = $this->order([
            'coupon_code' => 'TEN', 'discount_amount' => 30_00,
            'coupon_terms' => ['type' => 'percent', 'value' => 10, 'min_subtotal' => null, 'excluded' => 0],
        ]);

        $this->actingAs($this->admin)
            ->put("http://localhost/admin/orders/{$order->order_number}/discount", ['type' => 'percent', 'value' => '10', 'reason' => ''])
            ->assertOk()->assertSee('Say why');
        $this->assertNull($order->fresh()->manual_discount);

        $this->put("http://localhost/admin/orders/{$order->order_number}/discount", ['type' => 'percent', 'value' => '10', 'reason' => 'Regular customer'])
            ->assertOk();

        $order->refresh();
        // Coupon 30.00 off 300, then 10 % of the 270 left = 27.00.
        $this->assertSame(27_00, $order->manualDiscount());
        $this->assertSame(57_00, $order->discount_amount);
        $this->assertSame(243_00, $order->total);
        $this->assertSame('Regular customer', $order->manual_discount['reason']);
        $this->assertSame([['coupon', 30_00], ['manual', 27_00]], array_map(fn ($l) => [$l['code'], $l['amount']], $order->discountLines()));

        $this->delete("http://localhost/admin/orders/{$order->order_number}/discount")->assertOk();
        $this->assertSame(30_00, $order->fresh()->discount_amount);
    }

    public function test_replacing_the_managers_discount_on_an_order_whose_coupon_is_a_frozen_sum(): void
    {
        $order = $this->order(['coupon_code' => 'OLD', 'discount_amount' => 40_00]);
        $discounts = app(OrderDiscounts::class);

        $discounts->setManual($order, 'fixed', 10_00, 'first', $this->admin);
        app(OrderTotals::class)->recalculate($order);
        $this->assertSame(50_00, $order->fresh()->discount_amount);

        $order = $order->fresh();
        $discounts->setManual($order, 'fixed', 5_00, 'second', $this->admin);
        app(OrderTotals::class)->recalculate($order);
        $this->assertSame(45_00, $order->fresh()->discount_amount, 'the old manual part must not be read as coupon');
    }
}
