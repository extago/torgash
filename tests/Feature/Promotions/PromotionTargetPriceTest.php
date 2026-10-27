<?php

namespace Tests\Feature\Promotions;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Promotions\Models\Promotion;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * The other half of the usual four discount kinds: not how much comes off, but
 * what the price becomes. "Everything at 999" is the plainest sale a shop runs
 * and the one a storefront can print without doing arithmetic for the customer.
 *
 * The interesting cases are the ones where a target is not below the price —
 * a discount is never a surcharge — and where the money has to survive the
 * round trip through the admin form, which types major units and stores minor.
 */
class PromotionTargetPriceTest extends TestCase
{
    public function test_a_catalog_promotion_can_set_the_price_outright(): void
    {
        $dear = $this->product('TP-1', 'tp-1', 1500_00);
        $cheap = $this->product('TP-2', 'tp-2', 500_00);

        $this->catalogTier('to_amount', 999_00);

        $this->assertSame(999_00, $dear->fresh()->effectivePrice());
        $this->assertSame(500_00, $cheap->fresh()->effectivePrice(), 'a target above the price is not a surcharge');
    }

    public function test_a_catalog_promotion_can_set_the_price_to_a_percent_of_itself(): void
    {
        $product = $this->product('TP-3', 'tp-3', 1000_00);

        $this->catalogTier('to_percent', 20);

        $this->assertSame(200_00, $product->fresh()->effectivePrice(), 'down TO 20% of the price, not BY 20%');
    }

    /** The two spellings of the same sale must not be confused for one another. */
    public function test_to_percent_and_percent_off_are_different_promotions(): void
    {
        $product = $this->product('TP-4', 'tp-4', 1000_00);

        $this->catalogTier('percent', 20);

        $this->assertSame(800_00, $product->fresh()->effectivePrice());
    }

    public function test_a_cart_promotion_can_set_the_order_to_a_fixed_price(): void
    {
        $this->product('TP-5', 'tp-5', 250_00);

        $promotion = Promotion::create(['name' => ['en' => 'Everything at 199'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => 'to_amount', 'discount_value' => 199_00,
        ]);

        $this->post('http://localhost/cart/add/tp-5', ['qty' => 1])->assertOk();
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Target Buyer', 'email' => 'target@example.test',
            'address_line' => '1 Test Street', 'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ]);

        $order = Order::latest('id')->firstOrFail();
        $amount = (int) DB::table('m_promotions_order_promotions')->where('order_id', $order->id)->sum('amount');

        $this->assertSame(-51_00, $amount);
        $this->assertSame(199_00 + $order->shipping_cost, $order->total);
    }

    /**
     * The admin types 999, the column holds 99900. Through the real form, both
     * ways, because that conversion is keyed off the discount TYPE and a new
     * money-valued type is exactly the thing a `=== 'fixed'` check misses.
     */
    public function test_a_target_price_survives_the_admin_form_round_trip(): void
    {
        $this->actingAs(\App\Models\User::role('admin')->firstOrFail());

        $this->post('http://localhost/admin/promotions', [
            'name' => 'Everything at 999',
            'zone' => 'catalog',
            'combine' => 'replace',
            'priority' => 0,
            'enabled' => '1',
            'tiers' => [[
                'match' => 'all',
                'discount_type' => 'to_amount',
                'discount_value' => '999',
                'rules' => [],
            ]],
        ])->assertRedirect();

        $tier = Promotion::latest('id')->firstOrFail()->tiers()->firstOrFail();

        $this->assertSame('to_amount', $tier->discount_type);
        $this->assertSame(999_00, (int) $tier->discount_value, 'typed in major units, stored in minor');

        // And back out again, the way the edit screen reads it.
        $this->get('http://localhost/admin/promotions/'.$tier->promotion_id.'/edit')
            ->assertOk()
            ->assertSee('999', escape: false);
    }

    protected function catalogTier(string $type, int $value): Promotion
    {
        $promotion = Promotion::create(['name' => ['en' => 'Target'], 'zone' => 'catalog', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => $type, 'discount_value' => $value,
        ]);

        return $promotion;
    }

    protected function product(string $sku, string $slug, int $price): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => $slug,
            'price' => $price, 'stock' => 10, 'status' => 'active',
        ]);
    }
}
