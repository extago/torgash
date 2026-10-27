<?php

namespace Tests\Feature\Promotions;

use App\Models\CustomerGroup;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Promotions\Models\Promotion;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * give_group and free_product are not price lines — CartPricing::lines()
 * skips them entirely (PromotionScopedDiscountTest and friends never see
 * them). They are side effects, applied exactly once at real order
 * placement: a group upgrade on the account, a gift line added to the order
 * through the same stock deduction as everything else.
 */
class PromotionSideEffectsTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'GIFT-BASE', 'name' => ['en' => 'Qualifying product'], 'slug' => 'gift-base',
            'price' => 100_00, 'stock' => 10, 'status' => 'active',
        ]);
    }

    public function test_a_give_group_tier_upgrades_the_buyers_account_on_placement(): void
    {
        $user = User::factory()->create();
        $vip = CustomerGroup::create(['name' => ['en' => 'VIP']]);

        Promotion::create(['name' => ['en' => 'VIP unlock'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true])
            ->tiers()->create([
                'position' => 0,
                'conditions' => ['match' => 'all', 'rules' => [['kind' => 'subtotal', 'operator' => 'subtotal_gte', 'value' => 0]]],
                'discount_type' => 'give_group', 'give_group_id' => $vip->id,
            ]);

        $this->actingAs($user)->place();

        $this->assertSame($vip->id, $user->fresh()->customer_group_id);

        // A side effect, not a discount — the order still pays full price.
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(100_00 + $order->shipping_cost, $order->total);
    }

    public function test_a_free_product_tier_adds_a_gift_line_and_takes_its_stock(): void
    {
        $gift = Product::create([
            'sku' => 'GIFT-ITEM', 'name' => ['en' => 'Free gift'], 'slug' => 'gift-item',
            'price' => 50_00, 'stock' => 3, 'status' => 'active',
        ]);

        Promotion::create(['name' => ['en' => 'Buy one get a gift'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true])
            ->tiers()->create([
                'position' => 0,
                'conditions' => ['match' => 'all', 'rules' => [['kind' => 'subtotal', 'operator' => 'subtotal_gte', 'value' => 0]]],
                'discount_type' => 'free_product', 'free_product_id' => $gift->id, 'free_qty' => 2,
            ]);

        $this->place();

        $order = Order::latest('id')->firstOrFail();
        $giftLine = collect($order->items)->firstWhere('product_id', $gift->id);

        $this->assertNotNull($giftLine, 'the gift line was never added to the order');
        $this->assertSame(2, $giftLine['qty']);
        $this->assertSame(0, $giftLine['unit_price']);
        $this->assertSame(0, $giftLine['line_total']);

        // The gift went through the same stock deduction as everything else.
        $this->assertSame(1, $gift->fresh()->stock);

        // The gift contributed nothing to the price the customer agreed to.
        $this->assertSame(100_00 + $order->shipping_cost, $order->total);
    }

    protected function place(): void
    {
        $this->post('http://localhost/cart/add/gift-base', ['qty' => 1])->assertOk();
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Gift Buyer',
            'email' => 'gift-'.uniqid().'@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ]);
    }
}
