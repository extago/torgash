<?php

namespace Tests\Feature\Promotions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Promotions\Models\Promotion;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * The cap and the "once per customer" gate — cart zone only, same idiom as
 * Coupon's own usage_limit/used_count. Both are checked against
 * m_promotions_order_promotions, the same table the discount amount itself
 * is snapshotted to.
 */
class PromotionUsageLimitsTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'USELIM-1', 'name' => ['en' => 'Usage limit product'], 'slug' => 'usage-limit-product',
            'price' => 100_00, 'stock' => 10, 'status' => 'active',
        ]);
    }

    public function test_a_usage_limit_stops_the_discount_once_reached(): void
    {
        Promotion::create(['name' => ['en' => 'One redemption'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true, 'usage_limit' => 1])
            ->tiers()->create([
                'position' => 0,
                'conditions' => ['match' => 'all', 'rules' => [['kind' => 'subtotal', 'operator' => 'subtotal_gte', 'value' => 0]]],
                'discount_type' => 'percent', 'discount_value' => 10,
            ]);

        $this->place();
        $first = Order::latest('id')->firstOrFail();
        $this->assertSame(90_00, $first->total);

        $this->place();
        $second = Order::latest('id')->firstOrFail();
        $this->assertSame(100_00, $second->total, 'the cap was already spent by the first order');

        $this->assertSame(1, DB::table('m_promotions_promotions')->value('used_count'));
    }

    public function test_once_per_customer_blocks_a_second_order_from_the_same_account(): void
    {
        $user = User::factory()->create();

        Promotion::create(['name' => ['en' => 'Once each'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true, 'once_per_customer' => true])
            ->tiers()->create([
                'position' => 0,
                'conditions' => ['match' => 'all', 'rules' => [['kind' => 'subtotal', 'operator' => 'subtotal_gte', 'value' => 0]]],
                'discount_type' => 'percent', 'discount_value' => 10,
            ]);

        $this->actingAs($user)->place();
        $first = Order::latest('id')->firstOrFail();
        $this->assertSame(90_00, $first->total);

        $this->actingAs($user)->place();
        $second = Order::latest('id')->firstOrFail();
        $this->assertSame(100_00, $second->total, 'this account already used the promotion');
    }

    protected function place(): void
    {
        $this->post('http://localhost/cart/add/usage-limit-product', ['qty' => 1])->assertOk();
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Limit Buyer',
            'email' => 'limit-'.uniqid().'@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ]);
    }
}
