<?php

namespace Tests\Feature\Promotions;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Promotions\Models\Promotion;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\ZoneMatcher;

/**
 * Cart-zone promotions through the real checkout — the arithmetic being right
 * is only half of it; the order has to remember what it was given after the
 * promotion is edited or deleted, same reasoning as torgash.taxes' own
 * TaxedCheckoutTest.
 */
class CartPromotionTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'CARTPROMO-1', 'name' => ['en' => 'Cart promo product'], 'slug' => 'cart-promo-product',
            'price' => 100_00, 'stock' => 10, 'status' => 'active',
        ]);
    }

    public function test_a_subtotal_threshold_discounts_the_order_and_is_recorded(): void
    {
        $promotion = $this->subtotalPromotion(50_00, 10);

        $this->place(qty: 1);
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(100_00, $order->subtotal);
        $this->assertSame(100_00 - 10_00 + $order->shipping_cost, $order->total);

        $row = DB::table('m_promotions_order_promotions')->where('order_id', $order->id)->first();
        $this->assertSame(-10_00, (int) $row->amount);
        $this->assertSame($promotion->id, (int) $row->promotion_id);
    }

    public function test_below_the_threshold_nothing_is_discounted(): void
    {
        $this->subtotalPromotion(500_00, 10);

        $this->place(qty: 1);
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(100_00 + $order->shipping_cost, $order->total);
        $this->assertSame(0, DB::table('m_promotions_order_promotions')->where('order_id', $order->id)->count());
    }

    /** Same invariant as taxes: a placed order keeps what it was actually given. */
    public function test_editing_the_promotion_afterwards_does_not_move_a_placed_order(): void
    {
        $promotion = $this->subtotalPromotion(50_00, 10);

        $this->place(qty: 1);
        $order = Order::latest('id')->firstOrFail();
        $totalWhenPlaced = $order->total;

        $promotion->tiers()->first()->update(['discount_value' => 90]);

        $lines = hook_filter('checkout.order.extra_totals', [], ['order' => $order->fresh()]);

        $this->assertSame($totalWhenPlaced, $order->fresh()->total);
        $this->assertSame(-10_00, $lines[0]['amount']);
    }

    public function test_a_free_shipping_tier_zeroes_the_shipping_cost_on_the_order(): void
    {
        $method = ShippingMethod::create(['name' => ['en' => 'Paid courier'], 'position' => 99, 'enabled' => true]);
        $method->rates()->create(['zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 30_00, 'enabled' => true]);

        $promotion = Promotion::create(['name' => ['en' => 'Free ship'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => [['kind' => 'subtotal', 'operator' => 'subtotal_gte', 'value' => 0]]],
            'discount_type' => 'free_shipping',
        ]);

        $this->place(qty: 1, shippingMethodId: $method->id);
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(30_00, $order->shipping_cost);
        $this->assertSame(100_00, $order->total, 'the free-shipping line should cancel the 30.00 shipping cost');
    }

    protected function subtotalPromotion(int $minorThreshold, int $percent): Promotion
    {
        $promotion = Promotion::create(['name' => ['en' => "Spend {$minorThreshold}"], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => [['kind' => 'subtotal', 'operator' => 'subtotal_gte', 'value' => $minorThreshold]]],
            'discount_type' => 'percent', 'discount_value' => $percent,
        ]);

        return $promotion;
    }

    protected function place(int $qty, ?int $shippingMethodId = null): void
    {
        $this->post('http://localhost/cart/add/cart-promo-product', ['qty' => $qty])->assertOk();
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Promo Buyer',
            'email' => 'promo@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => $shippingMethodId ?? ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ]);
    }
}
