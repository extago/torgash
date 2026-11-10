<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\Fixtures\Payments\FakeGateway;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Models\Payment;
use Torgash\Payments\Models\Transaction;
use Torgash\Payments\PaymentActions;
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

    /**
     * Changing nothing NOW was not enough: the row used to carry the order, the
     * invoice id, "paid" and the amount, and a row like that begins a payment.
     * The next thing to recalculate the order — any genuine message about it —
     * then counted the forgery as money.
     */
    public function test_an_unsigned_paid_leaves_nothing_a_later_message_could_count(): void
    {
        $order = $this->order();

        $this->postJson('http://localhost/payment/fake/callback', [
            'status' => 'success', 'amount' => 100_00, 'reference' => $order->id,
            'invoiceId' => 'forged-1', 'messageId' => 'forged-m',
        ], ['X-Signature' => 'forged'])->assertOk();

        $this->assertSame(0, Payment::where('order_id', $order->id)->count());

        $kept = Transaction::latest('id')->firstOrFail();
        $this->assertSame(__('Signature did not verify — ignored'), $kept->summary);
        $this->assertNull($kept->order_id);
        $this->assertNull($kept->payment_id);
        $this->assertNull($kept->external_id);
        $this->assertNull($kept->event_key);
        $this->assertNull($kept->state);
        $this->assertNull($kept->amount);
        // What arrived is still there for whoever is looking for a wrong secret.
        $this->assertStringContainsString('forged-1', $kept->raw['body']);

        $this->sendCallback($order, ['status' => 'processing', 'invoiceId' => 'real-1', 'messageId' => 'real-m'])->assertOk();

        $order->refresh();
        $this->assertSame(PaymentState::PENDING, $order->payment_state);
        $this->assertSame(0, (int) $order->paid_total);
    }

    /** The one place a line in the log turns into money, guarded on its own: gateway modules write these rows too. */
    public function test_a_row_nobody_vouched_for_begins_no_payment(): void
    {
        $order = $this->order();

        Transaction::create([
            'gateway' => 'fake', 'order_id' => $order->id, 'external_id' => 'inv-x',
            'state' => PaymentState::PAID, 'amount' => 100_00,
            'verified' => false, 'applied' => false, 'created_at' => now(),
        ]);

        $this->assertSame(0, Payment::where('order_id', $order->id)->count());
    }

    public function test_an_unsigned_message_cannot_use_up_the_key_of_the_real_one(): void
    {
        $order = $this->order();
        $body = ['status' => 'success', 'amount' => 100_00, 'reference' => $order->id, 'messageId' => 'm-1'];

        $this->postJson('http://localhost/payment/fake/callback', $body, ['X-Signature' => 'forged'])->assertOk();
        $this->sendCallback($order, $body)->assertOk();

        $this->assertSame(PaymentState::PAID, $order->fresh()->payment_state);
    }

    /** Anybody can post here, and a row per post is a free way to fill the table. */
    public function test_unsigned_messages_cannot_fill_the_log_or_stop_the_real_ones(): void
    {
        $order = $this->order();

        for ($i = 0; $i < 65; $i++) {
            $this->postJson('http://localhost/payment/fake/callback', ['status' => 'success', 'reference' => $order->id], ['X-Signature' => 'forged'])
                ->assertOk();
        }

        $this->assertSame(60, Transaction::where('verified', false)->count());

        $this->sendCallback($order, ['status' => 'success', 'amount' => 100_00, 'messageId' => 'm-1'])->assertOk();

        $this->assertSame(PaymentState::PAID, $order->fresh()->payment_state);
    }

    /** A jsonb column takes neither a broken sequence nor a NUL, and this body is anybody's. */
    public function test_an_unsigned_body_the_database_would_refuse_is_still_answered_and_kept(): void
    {
        $this->call('POST', 'http://localhost/payment/fake/callback', [], [], [],
            ['HTTP_X-Signature' => 'forged', 'CONTENT_TYPE' => 'application/json'],
            "{\"a\":\"\xFF\0b\"}".str_repeat('x', 100_000),
        )->assertOk();

        $kept = Transaction::latest('id')->firstOrFail();
        $this->assertFalse($kept->verified);
        $this->assertLessThanOrEqual(4096 * 3, strlen($kept->raw['body']));
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

    // ------------------------------------------ what arrived, against what was asked

    public function test_less_than_the_invoice_is_money_received_and_not_a_paid_order(): void
    {
        Log::spy();
        $order = $this->awaiting();
        $this->invoiced($order);

        $this->sendCallback($order, ['status' => 'success', 'amount' => 1, 'invoiceId' => 'inv-1', 'messageId' => 'm-1'])->assertOk();

        $order->refresh();
        $this->assertSame(PaymentState::PARTIALLY_PAID, $order->payment_state, 'a kopeck is not the order paid');
        $this->assertSame(1, $order->paid_total, 'what did arrive is still written down');
        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->status, 'no goods leave for it');
        $this->assertFalse((bool) $order->stock_deducted);
        $this->assertTrue($order->logs()->where('description', 'like', '%less than the invoice%')->exists(), 'the manager is told on the order');
        Log::shouldHaveReceived('error')->once();
    }

    public function test_the_rest_arriving_on_a_second_invoice_pays_the_order(): void
    {
        $order = $this->awaiting();
        $this->invoiced($order);
        $this->sendCallback($order, ['status' => 'success', 'amount' => 40_00, 'invoiceId' => 'inv-1', 'messageId' => 'm-1'])->assertOk();

        $this->invoiced($order->fresh(), 'inv-2', 60_00);
        $this->sendCallback($order, ['status' => 'success', 'amount' => 60_00, 'invoiceId' => 'inv-2', 'messageId' => 'm-2'])->assertOk();

        $order->refresh();
        $this->assertSame(PaymentState::PAID, $order->payment_state);
        $this->assertSame(100_00, $order->paid_total);
        $this->assertNotSame(OrderStatus::AWAITING_PAYMENT, $order->status, 'all of it is here now, the order moves on');
    }

    public function test_a_late_message_does_not_take_away_what_arrived(): void
    {
        $order = $this->awaiting();
        $this->invoiced($order);
        $this->sendCallback($order, ['status' => 'success', 'amount' => 40_00, 'invoiceId' => 'inv-1', 'messageId' => 'm-1'])->assertOk();
        $this->sendCallback($order, ['status' => 'processing', 'invoiceId' => 'inv-1', 'messageId' => 'm-2'])->assertOk();

        $this->assertSame(40_00, $order->refresh()->paid_total);
    }

    public function test_a_payment_in_another_currency_changes_nothing_and_is_reported(): void
    {
        Log::spy();
        $order = $this->awaiting();
        $this->invoiced($order);

        $this->sendCallback($order, ['status' => 'success', 'amount' => 100_00, 'currency' => 'UAH', 'invoiceId' => 'inv-1', 'messageId' => 'm-1'])->assertOk();

        $order->refresh();
        $this->assertSame(PaymentState::PENDING, $order->payment_state, '100 hryvnia is not 100 dollars');
        $this->assertSame(0, (int) $order->paid_total);
        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->status);

        $row = Transaction::where('event_key', 'm-1')->firstOrFail();
        $this->assertFalse($row->applied);
        $this->assertNull($row->amount, 'a number in the wrong currency is not a number in the books');
        $this->assertTrue($order->logs()->where('description', 'like', '%another currency%')->exists());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_the_same_currency_in_other_letters_is_the_same_currency(): void
    {
        $order = $this->awaiting();
        $this->invoiced($order);

        $this->sendCallback($order, ['status' => 'success', 'amount' => 100_00, 'currency' => 'usd', 'invoiceId' => 'inv-1', 'messageId' => 'm-1'])->assertOk();

        $this->assertSame(PaymentState::PAID, $order->refresh()->payment_state);
    }

    public function test_the_amount_is_compared_in_the_currency_the_bank_was_asked_in(): void
    {
        $order = $this->awaiting(['payment_currency' => 'UAH', 'payment_rate' => 41.5]);
        $this->invoiced($order);

        $this->sendCallback($order, ['status' => 'success', 'amount' => 4150_00, 'currency' => 'UAH', 'invoiceId' => 'inv-1', 'messageId' => 'm-1'])->assertOk();

        $order->refresh();
        $this->assertSame(PaymentState::PAID, $order->payment_state);
        $this->assertSame(100_00, $order->paid_total);

        $less = $this->awaiting(['payment_currency' => 'UAH', 'payment_rate' => 41.5]);
        $this->invoiced($less, 'inv-2');

        // A hundred hryvnia reads as "100.00" and would pass against a dollar sum.
        $this->sendCallback($less, ['status' => 'success', 'amount' => 100_00, 'currency' => 'UAH', 'invoiceId' => 'inv-2', 'messageId' => 'm-2'])->assertOk();

        $this->assertSame(PaymentState::PARTIALLY_PAID, $less->refresh()->payment_state);
    }

    public function test_the_invoice_is_what_counts_not_the_order_edited_since(): void
    {
        $order = $this->awaiting();
        $this->invoiced($order);
        $order->update(['total' => 120_00]);

        $this->sendCallback($order, ['status' => 'success', 'amount' => 100_00, 'invoiceId' => 'inv-1', 'messageId' => 'm-1'])->assertOk();

        $order->refresh();
        $this->assertNotSame(OrderStatus::AWAITING_PAYMENT, $order->status, 'the customer paid what they were asked for');
        $this->assertSame(20_00, $order->balance());
    }

    public function test_an_invoice_nobody_wrote_down_is_measured_against_what_is_due(): void
    {
        $order = $this->awaiting();

        $this->sendCallback($order, ['status' => 'success', 'amount' => 5_00, 'invoiceId' => 'never-seen', 'messageId' => 'm-1'])->assertOk();

        $order->refresh();
        $this->assertSame(PaymentState::PARTIALLY_PAID, $order->payment_state);
        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->status);
    }

    public function test_a_short_hold_does_not_release_the_goods(): void
    {
        $order = $this->awaiting();
        $this->invoiced($order);

        $this->sendCallback($order, ['status' => 'hold', 'amount' => 1, 'invoiceId' => 'inv-1', 'messageId' => 'm-1'])->assertOk();

        $order->refresh();
        $this->assertSame(PaymentState::AUTHORIZED, $order->payment_state, 'a hold is still a hold');
        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->status);
    }

    public function test_a_manager_taking_part_of_a_hold_is_not_a_shortfall(): void
    {
        $order = $this->awaiting();
        $payment = $this->invoiced($order);
        $this->sendCallback($order, ['status' => 'hold', 'amount' => 100_00, 'invoiceId' => 'inv-1', 'messageId' => 'm-1'])->assertOk();

        app(PaymentActions::class)->act($payment->fresh(), 'capture', 60_00);

        // The bank's own word about it afterwards, as Stripe sends one.
        $this->sendCallback($order, ['status' => 'success', 'amount' => 60_00, 'invoiceId' => 'inv-1', 'messageId' => 'm-2'])->assertOk();

        $this->assertSame(PaymentState::PAID, $order->refresh()->payment_state);
    }

    // ------------------------------------------ whose secret a message is checked with

    public function test_a_message_is_checked_with_the_account_its_order_was_placed_through(): void
    {
        $order = $this->orderOfASecondAccount();

        $this->sign($order, 'SECOND-secret', 'm-own')->assertOk();

        $this->assertTrue(Transaction::where('event_key', 'm-own')->firstOrFail()->verified);
        $this->assertSame(PaymentState::PAID, $order->refresh()->payment_state);
    }

    public function test_the_secret_of_another_account_pays_nothing(): void
    {
        $order = $this->orderOfASecondAccount();

        // The first row's: a test account's, say, against a live order.
        $this->sign($order, $this->token, 'm-other')->assertOk();

        $this->assertSame(PaymentState::PENDING, $order->refresh()->payment_state);
        $this->assertSame(0, (int) $order->paid_total);
    }

    public function test_an_order_of_no_account_of_this_gateway_is_not_paid_by_the_others(): void
    {
        $order = $this->orderOfASecondAccount();
        Binding::where('config->token', 'SECOND-secret')->delete();
        Binding::create(['payment_method_id' => PaymentMethod::create(['name' => ['en' => 'Third'], 'enabled' => true, 'position' => 96])->id, 'gateway' => 'fake', 'config' => ['token' => 'third'], 'test_mode' => true]);

        $this->sign($order, $this->token, 'm-orphan')->assertOk();

        $this->assertSame(PaymentState::PENDING, $order->refresh()->payment_state);
    }

    public function test_a_gateway_with_no_account_verifies_nothing(): void
    {
        Binding::query()->delete();
        $order = $this->order();

        // Signed with the empty secret, which is all a gateway has with no account.
        $this->sign($order, '', 'm-empty')->assertOk();

        $this->assertSame(PaymentState::PENDING, $order->refresh()->payment_state);
    }

    public function test_a_body_that_cannot_be_read_is_refused_and_not_a_500_with_several_accounts(): void
    {
        $this->orderOfASecondAccount();

        foreach (['not json', '"text"', '[1,2]', '{"status":{"a":1},"reference":{"b":2}}'] as $body) {
            $this->call('POST', 'http://localhost/payment/fake/callback', [], [], [],
                ['HTTP_X-Signature' => 'nope', 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        }

        $this->assertSame(0, Transaction::where('verified', true)->count());
    }

    // ---------------------------------------------------------------- helpers

    protected function orderOfASecondAccount(): Order
    {
        $method = PaymentMethod::create(['name' => ['en' => 'Second account'], 'enabled' => true, 'position' => 95]);
        Binding::create(['payment_method_id' => $method->id, 'gateway' => 'fake', 'config' => ['token' => 'SECOND-secret'], 'test_mode' => false]);

        $order = $this->order();
        $order->update(['payment_method_name' => $method->t('name')]);

        return $order;
    }

    protected function sign(Order $order, string $secret, string $message)
    {
        $json = json_encode(['status' => 'success', 'amount' => 100_00, 'messageId' => $message, 'invoiceId' => 'i-'.$message, 'reference' => $order->id]);

        return $this->call('POST', 'http://localhost/payment/fake/callback', [], [], [],
            ['HTTP_X-Signature' => hash_hmac('sha256', $json, $secret), 'CONTENT_TYPE' => 'application/json'], $json);
    }

    /** @param  array<string, mixed>  $columns */
    protected function awaiting(array $columns = []): Order
    {
        $order = $this->order();
        $order->update(['status' => OrderStatus::AWAITING_PAYMENT] + $columns);

        return $order;
    }

    /** The row every gateway writes when it makes an invoice. */
    protected function invoiced(Order $order, string $id = 'inv-1', int $amount = 100_00): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'gateway' => 'fake',
            'external_id' => $id,
            'state' => PaymentState::PENDING,
            'requested' => $amount,
            'amount' => 0,
        ]);
    }

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
