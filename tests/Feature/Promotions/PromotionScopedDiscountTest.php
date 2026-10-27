<?php

namespace Tests\Feature\Promotions;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Promotions\Models\Promotion;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * "Discount on products / categories": a cart-zone
 * promotion whose MATCH condition is one thing (spend over a threshold) but
 * whose discount base is another (just the matching lines), independent of
 * the running "already cut" pool the plain (unscoped) case tracks.
 */
class PromotionScopedDiscountTest extends TestCase
{
    public function test_a_scoped_discount_only_discounts_the_matching_category(): void
    {
        $onSale = Category::create(['name' => ['en' => 'On sale'], 'slug' => 'scoped-on-sale']);
        $inScope = Product::create([
            'sku' => 'SCOPE-1', 'name' => ['en' => 'In scope'], 'slug' => 'scope-in',
            'price' => 100_00, 'stock' => 5, 'status' => 'active',
        ]);
        $inScope->categories()->attach($onSale);
        $outOfScope = Product::create([
            'sku' => 'SCOPE-2', 'name' => ['en' => 'Out of scope'], 'slug' => 'scope-out',
            'price' => 200_00, 'stock' => 5, 'status' => 'active',
        ]);

        $promotion = Promotion::create(['name' => ['en' => 'Category-only 10%'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => [['kind' => 'subtotal', 'operator' => 'subtotal_gte', 'value' => 0]]],
            'discount_type' => 'percent', 'discount_value' => 10,
            'discount_scope' => ['type' => 'category', 'ids' => [$onSale->id]],
        ]);

        $this->post('http://localhost/cart/add/scope-in', ['qty' => 1])->assertOk();
        $this->post('http://localhost/cart/add/scope-out', ['qty' => 1])->assertOk();
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Scope Buyer', 'email' => 'scope@example.test',
            'address_line' => '1 Test Street', 'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ]);

        $order = Order::latest('id')->firstOrFail();

        // 10% of the 100.00 in-category line only, not of the 300.00 subtotal.
        $this->assertSame(300_00 - 10_00 + $order->shipping_cost, $order->total);

        $row = DB::table('m_promotions_order_promotions')->where('order_id', $order->id)->first();
        $this->assertSame(-10_00, (int) $row->amount);
    }
}
