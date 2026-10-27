<?php

namespace Tests\Feature\Promotions;

use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Promotions\Models\Promotion;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * "Free shipping when paying by card" and the like — the usual `payment`
 * condition. Unlike shipping, an order's payment_method_id is a real,
 * durable column (§57), so this one can be checked reliably both live and
 * once the order is placed.
 */
class PromotionPaymentConditionTest extends TestCase
{
    protected Product $product;

    protected PaymentMethod $cardMethod;

    protected PaymentMethod $codMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'PAYCOND-1', 'name' => ['en' => 'Payment-conditioned product'], 'slug' => 'paycond-1',
            'price' => 100_00, 'stock' => 10, 'status' => 'active',
        ]);

        $this->cardMethod = PaymentMethod::create(['name' => ['en' => 'Card'], 'enabled' => true]);
        $this->codMethod = PaymentMethod::create(['name' => ['en' => 'Cash on delivery'], 'enabled' => true]);

        Promotion::create(['name' => ['en' => 'Pay by card, save 10%'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true])
            ->tiers()->create([
                'position' => 0,
                'conditions' => ['match' => 'all', 'rules' => [
                    ['kind' => 'payment', 'operator' => 'payment_eq', 'ids' => [$this->cardMethod->id]],
                ]],
                'discount_type' => 'percent', 'discount_value' => 10,
            ]);
    }

    public function test_the_discount_applies_only_for_the_named_method(): void
    {
        $this->place($this->cardMethod->id);
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(90_00 + $order->shipping_cost, $order->total);
    }

    public function test_a_different_method_gets_no_discount(): void
    {
        $this->place($this->codMethod->id);
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(100_00 + $order->shipping_cost, $order->total);
    }

    protected function place(int $paymentMethodId): void
    {
        $this->post('http://localhost/cart/add/paycond-1', ['qty' => 1])->assertOk();
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Payment Buyer',
            'email' => 'paycond-'.uniqid().'@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => $paymentMethodId,
        ]);
    }
}
