<?php

namespace Tests\Feature\Promotions;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Promotions\Models\Promotion;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * "Recheck the promotions" on a placed order (PLAN §40.6/4): the difference is
 * shown before anything moves, and only the second press applies it.
 */
class OrderRecheckTest extends TestCase
{
    public function test_the_difference_is_shown_first_and_applied_on_the_second_press(): void
    {
        Mail::fake();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Product::create(['sku' => 'RC-1', 'name' => ['en' => 'Chair'], 'slug' => 'rc-chair', 'price' => 1000_00, 'stock' => 5, 'status' => 'active']);
        $cash = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => -1]);

        $this->post('http://localhost/cart/add/rc-chair', ['qty' => 1]);
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test', 'address_line' => '1 Street', 'city' => 'Town',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $cash->id,
        ])->assertSessionHasNoErrors();
        $order = Order::latest('id')->firstOrFail();
        $total = $order->total;

        // The customer forgot: a sale that started after they ordered.
        $promotion = Promotion::create(['name' => ['en' => 'Ten off'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => [['kind' => 'subtotal', 'operator' => 'subtotal_gte', 'value' => 0]]],
            'discount_type' => 'percent', 'discount_value' => 10,
        ]);

        $this->actingAs($admin)->get('http://localhost/admin/orders/'.$order->order_number)->assertOk();
        $this->actingAs($admin)->post('http://localhost/admin/promotions/orders/'.$order->order_number.'/recheck')
            ->assertOk()->assertSee($order->adminMoney(100_00));
        $this->assertSame($total, $order->fresh()->total, 'nothing moves on a preview');

        $this->actingAs($admin)->post('http://localhost/admin/promotions/orders/'.$order->order_number.'/reapply')->assertOk();
        $this->assertSame($total - 100_00, $order->fresh()->total);
    }
}
