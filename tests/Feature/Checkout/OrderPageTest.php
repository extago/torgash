<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;

/**
 * The order's own page: inside the account for its owner — every line with
 * its picture and page, the sums, delivery, and the way the order went, as a
 * customer is told it (placed, statuses, payment), never the shop's notes.
 */
class OrderPageTest extends TestCase
{
    public function test_the_owner_reads_the_whole_order_inside_the_account(): void
    {
        $user = User::factory()->create();
        $product = Product::create([
            'sku' => 'OP-1', 'name' => ['en' => 'Order Page Phone'], 'slug' => 'order-page-phone',
            'price' => 250_00, 'stock' => 5, 'status' => 'active',
        ]);
        $order = Order::create([
            'user_id' => $user->id, 'customer_name' => 'Buyer', 'email' => 'buyer@example.test',
            'address_line' => '1 Test Street', 'currency_code' => 'USD',
            'subtotal' => 500_00, 'shipping_cost' => 0, 'total' => 500_00, 'status' => 'new',
            'items' => [['product_id' => $product->id, 'sku' => 'OP-1', 'name' => 'Order Page Phone', 'qty' => 2, 'unit_price' => 250_00, 'line_total' => 500_00]],
        ]);
        $order->update(['order_number' => 'TG-OP'.$order->id]);
        OrderLog::record($order, 'Order placed');
        OrderLog::record($order, 'A note only the staff should read');

        $this->get('http://localhost/');
        $html = $this->actingAs($user)->get(route('checkout.confirmation', $order->order_number))->assertOk()->getContent();

        $this->assertStringContainsString('torgash-account-nav', $html, 'the account menu beside it');
        $this->assertStringContainsString(route('catalog.product', 'order-page-phone'), $html);
        $this->assertStringContainsString('buyer@example.test', $html);
        $this->assertStringContainsString(__('Placed'), $html);
        $this->assertStringNotContainsString('A note only the staff should read', $html);
        $this->assertStringNotContainsString(__('Thank you!'), $html, 'thanks only on the way out of checkout');
    }
}
