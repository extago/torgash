<?php

namespace Tests\Feature\Promotions;

use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Coupon;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Promotions\Models\Promotion;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\ZoneMatcher;

/**
 * The ceiling on what promotions may take.
 *
 * Every tier has always been capped against its own base by
 * PromotionTier::discountFor(); nothing capped the SUM. Two stacking 60%
 * promotions therefore placed an order with a total of -20.00 — an order the
 * shop owes the buyer money on — and a coupon, which is a different module's
 * column rather than a line on this hook, was invisible to the whole
 * calculation, so both discounts were computed off the same untouched
 * subtotal.
 *
 * These are the money tests: a promotion may leave the customer paying
 * nothing, and may never leave them owed anything.
 */
class PromotionDiscountCapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Product::create([
            'sku' => 'CAP-1', 'name' => ['en' => 'Capped'], 'slug' => 'capped',
            'price' => 100_00, 'stock' => 10, 'status' => 'active',
        ]);
    }

    public function test_stacked_promotions_never_take_more_than_the_cart(): void
    {
        $this->stackAdd('Stack A', 60);
        $this->stackAdd('Stack B', 60);

        $this->place();
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(100_00, $order->subtotal);
        $this->assertSame($order->shipping_cost, $order->total, 'the goods are free, but never worth less than nothing');
        $this->assertGreaterThanOrEqual(0, $order->total);

        // 60.00 then whatever was left of the cart, not 60.00 twice.
        $this->assertSame(-100_00, (int) $this->recorded()->sum('amount'));
    }

    public function test_a_promotion_discounts_what_is_left_after_the_coupon(): void
    {
        Coupon::create(['code' => 'MINUS40', 'type' => 'fixed', 'value' => 40_00, 'enabled' => true]);
        $this->stackAdd('Half off', 50);

        $this->post('http://localhost/cart/add/capped', ['qty' => 1])->assertOk();
        $this->post('http://localhost/cart/coupon', ['code' => 'MINUS40'])->assertOk();
        $this->post('http://localhost/checkout', $this->form());

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(40_00, $order->discount_amount);
        // 50% of the 60.00 still owed, not of the 100.00 sticker price.
        $this->assertSame(-30_00, (int) $this->recorded()->sum('amount'));
        $this->assertSame(30_00 + $order->shipping_cost, $order->total);
    }

    public function test_a_full_coupon_leaves_a_promotion_nothing_to_take(): void
    {
        Coupon::create(['code' => 'ALLOFF', 'type' => 'percent', 'value' => 100, 'enabled' => true]);
        $this->stackAdd('Half off', 50);

        $this->post('http://localhost/cart/add/capped', ['qty' => 1])->assertOk();
        $this->post('http://localhost/cart/coupon', ['code' => 'ALLOFF'])->assertOk();
        $this->post('http://localhost/checkout', $this->form());

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame($order->shipping_cost, $order->total);
        $this->assertSame(0, $this->recorded()->count(), 'a promotion that took nothing writes no line and spends no redemption');
    }

    public function test_two_free_shipping_promotions_refund_one_delivery(): void
    {
        $method = ShippingMethod::create(['name' => ['en' => 'Paid courier'], 'position' => 99, 'enabled' => true]);
        $method->rates()->create(['zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 30_00, 'enabled' => true]);

        $this->freeShipping('Free ship A');
        $this->freeShipping('Free ship B');

        $this->place($method->id);
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(30_00, $order->shipping_cost);
        $this->assertSame(100_00, $order->total, 'the delivery is cancelled once, not paid back twice');
    }

    protected function stackAdd(string $name, int $percent): Promotion
    {
        $promotion = Promotion::create(['name' => ['en' => $name], 'zone' => 'cart', 'combine' => 'stack_add', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => 'percent', 'discount_value' => $percent,
        ]);

        return $promotion;
    }

    protected function freeShipping(string $name): Promotion
    {
        $promotion = Promotion::create(['name' => ['en' => $name], 'zone' => 'cart', 'combine' => 'stack_add', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => 'free_shipping',
        ]);

        return $promotion;
    }

    protected function recorded()
    {
        return \Illuminate\Support\Facades\DB::table('m_promotions_order_promotions')
            ->where('order_id', Order::latest('id')->value('id'))
            ->get();
    }

    protected function place(?int $shippingMethodId = null): void
    {
        $this->post('http://localhost/cart/add/capped', ['qty' => 1])->assertOk();
        $this->post('http://localhost/checkout', $this->form($shippingMethodId));
    }

    protected function form(?int $shippingMethodId = null): array
    {
        return [
            'customer_name' => 'Cap Buyer',
            'email' => 'cap@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => $shippingMethodId ?? ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ];
    }
}
