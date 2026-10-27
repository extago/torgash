<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use App\Core\Mail\EventMail;
use Torgash\Checkout\Mail\CheckoutLetters;
use Torgash\Checkout\Models\Coupon;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * The money path, end to end: cart → coupon → order.
 *
 * Every number here is in minor units. This is the one flow where a rounding
 * or ordering mistake is a real loss rather than a cosmetic bug — the discount
 * must come off before shipping is added, and the order must record what the
 * customer was actually shown.
 */
class CheckoutOrderTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'TEST-1',
            'name' => ['en' => 'Test product', 'ru' => 'Тестовый товар'],
            'slug' => 'test-product',
            'price' => 100_00,
            'stock' => 10,
            'status' => 'active',
        ]);
    }

    public function test_a_guest_can_place_an_order_and_the_totals_match_what_the_cart_showed(): void
    {
        Mail::fake();

        // Adding to the cart answers with the HTMX badge fragment, not a
        // redirect — the storefront never leaves the page for it.
        $this->post('/cart/add/test-product', ['qty' => 2])->assertOk();

        $response = $this->post('/checkout', $this->form());

        $order = Order::latest('id')->firstOrFail();
        $response->assertRedirect(route('checkout.confirmation', $order->order_number));

        $this->assertSame(200_00, $order->subtotal);
        $this->assertSame(0, $order->discount_amount);
        $this->assertSame(200_00 + $order->shipping_cost, $order->total);
        $this->assertSame('guest@example.test', $order->email);
        $this->assertCount(1, $order->items);
        $this->assertSame(2, $order->items[0]['qty']);
    }

    public function test_a_percentage_coupon_comes_off_before_shipping_is_added(): void
    {
        Coupon::create(['code' => 'SAVE10', 'type' => 'percent', 'value' => 10, 'enabled' => true]);

        $this->post('/cart/add/test-product', ['qty' => 2]);
        $this->post('/cart/coupon', ['code' => 'SAVE10'])->assertOk();

        $this->post('/checkout', $this->form());

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame('SAVE10', $order->coupon_code);
        $this->assertSame(20_00, $order->discount_amount);
        $this->assertSame(180_00 + $order->shipping_cost, $order->total);
    }

    public function test_placing_an_order_deducts_stock(): void
    {
        $this->post('/cart/add/test-product', ['qty' => 3]);
        $this->post('/checkout', $this->form());

        $this->assertSame(7, $this->product->fresh()->stock);
    }

    /**
     * The confirmation email must be queued, never sent inline: an unreachable
     * mail server used to throw after the order was already committed, so the
     * customer got an error page for an order the shop had taken.
     */
    public function test_the_confirmation_email_is_queued_not_sent_during_the_request(): void
    {
        Mail::fake();

        $this->post('/cart/add/test-product', ['qty' => 1]);
        $this->post('/checkout', $this->form());

        Mail::assertQueued(EventMail::class, fn ($mail) => $mail->event === CheckoutLetters::PLACED);
        Mail::assertNothingSent();
    }

    public function test_checkout_with_an_empty_cart_goes_back_to_the_cart(): void
    {
        $this->post('/checkout', $this->form())->assertRedirect(route('cart.index'));

        $this->assertSame(0, Order::count());
    }

    public function test_the_order_remembers_the_locale_it_was_placed_in(): void
    {
        $this->post('http://localhost/ru/cart/add/test-product', ['qty' => 1]);
        $this->post('http://localhost/ru/checkout', $this->form());

        $this->assertSame('ru', Order::latest('id')->firstOrFail()->locale);
    }

    protected function form(): array
    {
        return [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ];
    }
}
