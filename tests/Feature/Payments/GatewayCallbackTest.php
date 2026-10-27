<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\Fixtures\Payments\FakeGateway;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Models\Transaction;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * Taking money: start, callback, and what the callback is allowed to do.
 *
 * Four of these are the reasons this module exists at all, each one a mistake a
 * real add-on makes — a callback whose signature does not check out must change
 * nothing, a redelivered one must change nothing twice, a late one must not
 * walk the order backwards, and the browser coming back from the payment page
 * must not be evidence of anything.
 */
class GatewayCallbackTest extends TestCase
{
    protected string $token = 'sekret';

    protected Product $product;

    protected PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        FakeGateway::reset();

        hook_listen('payments.gateways', fn (array $g) => $g + ['fake' => FakeGateway::class]);

        $this->product = Product::create([
            'sku' => 'PAY-G1',
            'name' => ['en' => 'Payable widget'],
            'slug' => 'payable-gateway-widget',
            'price' => 50_00,
            'stock' => 10,
            'status' => 'active',
        ]);

        $this->method = PaymentMethod::where('enabled', true)->orderBy('id')->firstOrFail();

        Binding::create([
            'payment_method_id' => $this->method->id,
            'gateway' => 'fake',
            'config' => ['token' => $this->token],
            'test_mode' => true,
        ]);
    }

    // ----------------------------------------------------------------- start

    public function test_placing_an_order_sends_the_customer_to_the_gateway(): void
    {
        $this->post('http://localhost/cart/add/payable-gateway-widget', ['qty' => 1])->assertOk();

        $this->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => $this->method->id,
        ])->assertRedirect('https://pay.example.test/invoice/1');

        // The order exists and is unpaid: it is created BEFORE the money, and
        // the customer may simply close the tab on the payment page.
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(PaymentState::PENDING, $order->payment_state);
        // The gateway is TOLD what to charge and in what — it does not read it
        // off the order, because the shop's books and the customer's currency
        // are two different things (§51).
        $this->assertSame([[
            'order' => $order->id,
            'amount' => $order->total,
            'currency' => $order->currency_code,
            // Its own fields plus the binding's test switch (Binding::gatewayConfig).
            'config' => ['test_mode' => true, 'token' => $this->token],
        ]], FakeGateway::$started);
    }

    public function test_a_customer_can_come_back_and_pay_later(): void
    {
        $order = $this->order();

        // An invoice expires and people close tabs; without this the only way
        // back to a payment page is placing the order again.
        $this->withSession(['last_order_number' => $order->order_number])
            ->get('http://localhost/checkout/'.$order->order_number.'/confirmation')
            ->assertOk()
            ->assertSee('/payment/'.$order->order_number.'/pay', false);

        $this->get(URL::signedRoute('payments.pay', $order->order_number))
            ->assertRedirect('https://pay.example.test/invoice/1');
    }

    public function test_the_pay_link_cannot_be_guessed_from_an_order_number(): void
    {
        $order = $this->order();

        // Signed rather than session-guarded: it has to work from tomorrow's
        // email, and an order number is TG-000123.
        $this->get('http://localhost/payment/'.$order->order_number.'/pay')->assertForbidden();
    }

    public function test_a_paid_order_is_not_asked_to_pay_again(): void
    {
        $order = $this->order();
        $this->sendCallback($order, ['status' => 'success', 'amount' => 100_00, 'messageId' => 'm-1'])->assertOk();

        $this->withSession(['last_order_number' => $order->order_number])
            ->get('http://localhost/checkout/'.$order->order_number.'/confirmation')
            ->assertOk()
            ->assertDontSee('/payment/'.$order->order_number.'/pay', false);
    }

    // -------------------------------------------------------------- callback

    public function test_the_callback_route_carries_no_session_no_csrf_and_no_locale(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($r) => $r->uri() === 'payment/{gateway}/callback');

        // A gateway stored this URL months ago, has no cookies and no token; the
        // "web" group would answer its callback with a 419 that looks, from its
        // side, exactly like the shop being down.
        $this->assertNotNull($route);
        $this->assertNotContains('web', $route->gatherMiddleware());
    }

    public function test_a_verified_callback_pays_the_order_once(): void
    {
        $order = $this->order();

        $this->sendCallback($order, ['status' => 'success', 'amount' => 100_00, 'messageId' => 'm-1'])
            ->assertOk();

        $order->refresh();

        $this->assertSame(PaymentState::PAID, $order->payment_state);
        $this->assertSame(100_00, $order->paid_total);
        $this->assertNotNull($order->paid_at);
        $this->assertTrue($order->logs()->get()->contains(fn ($log) => str_starts_with($log->text(), 'fake:')));

        $transaction = Transaction::latest('id')->firstOrFail();
        $this->assertTrue($transaction->verified);
        $this->assertTrue($transaction->applied);
    }

    public function test_a_callback_that_does_not_verify_changes_nothing_but_is_kept(): void
    {
        $order = $this->order();

        $this->post(
            'http://localhost/payment/fake/callback',
            [],
            ['X-Signature' => 'not-even-close', 'CONTENT_TYPE' => 'application/json'],
        )->assertOk();

        $this->postJson('http://localhost/payment/fake/callback', [
            'status' => 'success', 'amount' => 100_00, 'reference' => $order->id,
        ], ['X-Signature' => 'forged'])->assertOk();

        $this->assertSame(PaymentState::PENDING, $order->fresh()->payment_state);

        $transaction = Transaction::latest('id')->firstOrFail();
        $this->assertFalse($transaction->verified);
        $this->assertFalse($transaction->applied);
    }

    public function test_the_same_message_twice_is_one_payment(): void
    {
        $order = $this->order();
        $body = ['status' => 'success', 'amount' => 100_00, 'messageId' => 'm-42'];

        $this->sendCallback($order, $body)->assertOk();
        $this->sendCallback($order, $body)->assertOk();

        $this->assertSame(1, Transaction::where('applied', true)->count());
        $this->assertSame(100_00, $order->fresh()->paid_total);
    }

    public function test_a_late_callback_does_not_walk_the_order_backwards(): void
    {
        $order = $this->order();

        $this->sendCallback($order, ['status' => 'success', 'amount' => 100_00, 'messageId' => 'm-1'])->assertOk();
        // The "processing" that was delayed behind the success it preceded.
        $this->sendCallback($order, ['status' => 'processing', 'messageId' => 'm-0'])->assertOk();

        $this->assertSame(PaymentState::PAID, $order->fresh()->payment_state);
        $this->assertSame(__('Out of order — ignored'), Transaction::latest('id')->firstOrFail()->summary);
    }

    public function test_a_refund_after_a_payment_is_later_news_and_is_applied(): void
    {
        $order = $this->order();

        $this->sendCallback($order, ['status' => 'success', 'amount' => 100_00, 'messageId' => 'm-1'])->assertOk();
        $this->sendCallback($order, ['status' => 'reversed', 'amount' => 0, 'messageId' => 'm-2'])->assertOk();

        $order->refresh();

        $this->assertSame(PaymentState::REFUNDED, $order->payment_state);
        $this->assertSame(0, $order->paid_total);
        $this->assertNull($order->paid_at);
    }

    public function test_a_callback_naming_no_order_is_recorded_and_dropped(): void
    {
        $this->postJson('http://localhost/payment/fake/callback', $body = [
            'status' => 'success', 'amount' => 100_00, 'reference' => 999999, 'messageId' => 'm-9',
        ], ['X-Signature' => hash_hmac('sha256', json_encode($body), $this->token)])->assertOk();

        $this->assertSame(__('No such order'), Transaction::latest('id')->firstOrFail()->summary);
    }

    // ----------------------------------------------------------------- admin

    public function test_an_admin_can_refund_through_the_same_path_a_callback_uses(): void
    {
        $order = $this->order();
        $this->sendCallback($order, ['status' => 'success', 'amount' => 100_00, 'messageId' => 'm-1'])->assertOk();

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/payments/orders/'.$order->order_number.'/refund')
            ->assertRedirect();

        $this->assertSame(PaymentState::REFUNDED, $order->fresh()->payment_state);
    }

    public function test_a_capability_the_gateway_does_not_have_is_refused(): void
    {
        $order = $this->order();

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/payments/orders/'.$order->order_number.'/cancel')
            ->assertRedirect();

        $this->assertSame(0, Transaction::count());
    }

    // ---------------------------------------------------------------- helpers

    /** @param  array<string, mixed>  $body */
    protected function sendCallback(Order $order, array $body)
    {
        $body['reference'] = $order->id;
        $json = json_encode($body);

        return $this->call(
            'POST',
            'http://localhost/payment/fake/callback',
            [], [], [],
            ['HTTP_X-Signature' => hash_hmac('sha256', $json, $this->token), 'CONTENT_TYPE' => 'application/json'],
            $json,
        );
    }

    protected function order(): Order
    {
        return Order::create([
            'order_number' => 'TG-PG'.random_int(1000, 9999),
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'currency_code' => 'USD',
            'subtotal' => 100_00,
            'shipping_cost' => 0,
            'total' => 100_00,
            'status' => 'new',
            'payment_method_name' => $this->method->t('name'),
            'stock_deducted' => false,
            'items' => [[
                'product_id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => 'Payable widget',
                'qty' => 2,
                'unit_price' => 50_00,
                'line_total' => 100_00,
            ]],
        ])->fresh();
    }
}
