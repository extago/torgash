<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\Payments\FakeGateway;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Checkout\Orders\Refund;
use Torgash\Payments\Jobs\ReconcilePayments;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Models\Payment;
use Torgash\Payments\Models\Transaction;

/**
 * An order's payments are units of their own (PLAN §40.10/4): "200 now, the
 * rest later" is two invoices at the bank. Read as "the order's latest
 * invoice", the rest arriving wrote the order's paid total as the rest alone,
 * and a refund or a capture reached only the second invoice.
 */
class OrderPaymentsTest extends TestCase
{
    protected string $token = 'sekret';

    protected PaymentMethod $method;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        FakeGateway::reset();

        hook_listen('payments.gateways', fn (array $g) => $g + ['fake' => FakeGateway::class]);

        $this->product = Product::create(['sku' => 'OP-1', 'name' => ['en' => 'Sofa'], 'slug' => 'op-sofa', 'price' => 100_00, 'stock' => 10, 'status' => 'active']);
        $this->method = PaymentMethod::where('enabled', true)->orderBy('id')->firstOrFail();
        Binding::create(['payment_method_id' => $this->method->id, 'gateway' => 'fake', 'config' => ['token' => $this->token], 'test_mode' => true]);
    }

    public function test_a_deposit_and_its_rest_are_two_payments_and_both_count(): void
    {
        $order = $this->order(['deposit_amount' => 20_00]);

        $this->notify($order, ['status' => 'success', 'invoiceId' => 'inv-dep', 'amount' => 20_00, 'messageId' => 'm-1']);
        $this->assertSame(PaymentState::PARTIALLY_PAID, $order->fresh()->payment_state);
        $this->assertSame(20_00, $order->fresh()->paid_total);

        // The rest, paid online after all — a second invoice.
        $this->notify($order, ['status' => 'success', 'invoiceId' => 'inv-rest', 'amount' => 80_00, 'messageId' => 'm-2']);

        $order->refresh();
        $this->assertSame(100_00, $order->paid_total, 'the deposit is still there');
        $this->assertSame(PaymentState::PAID, $order->payment_state);
        $this->assertSame([20_00, 80_00], Payment::where('order_id', $order->id)->orderBy('id')->pluck('amount')->all());
    }

    public function test_a_late_message_about_one_invoice_does_not_undo_another(): void
    {
        $order = $this->order(['deposit_amount' => 20_00]);
        $this->notify($order, ['status' => 'success', 'invoiceId' => 'inv-dep', 'amount' => 20_00, 'messageId' => 'm-1']);

        // The invoice for the rest was only just made: "created" is news about
        // IT, and the order keeps its deposit.
        $this->notify($order, ['status' => 'created', 'invoiceId' => 'inv-rest', 'messageId' => 'm-2']);

        $this->assertSame(PaymentState::PARTIALLY_PAID, $order->fresh()->payment_state);
        $this->assertSame(20_00, $order->fresh()->paid_total);
    }

    public function test_a_refund_of_the_order_goes_back_payment_by_payment(): void
    {
        $order = $this->order(['deposit_amount' => 20_00]);
        $this->notify($order, ['status' => 'success', 'invoiceId' => 'inv-dep', 'amount' => 20_00, 'messageId' => 'm-1']);
        $this->notify($order, ['status' => 'success', 'invoiceId' => 'inv-rest', 'amount' => 80_00, 'messageId' => 'm-2']);

        // Newest first, never more than a payment holds.
        $result = app(Refund::class)->refund($order->fresh(), 90_00);
        $this->assertTrue($result->done);
        $this->assertTrue($result->viaGateway);

        $this->assertSame(
            [['inv-rest', null], ['inv-dep', 10_00]],
            array_map(fn ($call) => [$call['params']['external_id'], $call['params']['amount'] ?? null], FakeGateway::$acted),
        );

        $order->refresh();
        $this->assertSame(10_00, $order->paid_total);
        $this->assertSame(PaymentState::PARTIALLY_REFUNDED, $order->payment_state);
    }

    public function test_a_hold_is_released_before_anything_taken_is_sent_back(): void
    {
        FakeGateway::$extraCapabilities = ['void'];
        $order = $this->order();
        $this->notify($order, ['status' => 'success', 'invoiceId' => 'inv-paid', 'amount' => 30_00, 'messageId' => 'm-1']);
        $this->notify($order, ['status' => 'hold', 'invoiceId' => 'inv-hold', 'amount' => 70_00, 'messageId' => 'm-2']);
        $this->assertSame(PaymentState::AUTHORIZED, $order->fresh()->payment_state);

        app(Refund::class)->refund($order->fresh(), 70_00);

        $this->assertSame([['void', 'inv-hold']], array_map(fn ($call) => [$call['capability'], $call['params']['external_id']], FakeGateway::$acted));
        $this->assertSame(30_00, $order->fresh()->paid_total);
        $this->assertSame(PaymentState::PARTIALLY_REFUNDED, $order->fresh()->payment_state);
    }

    public function test_the_order_screen_acts_on_the_payment_it_names(): void
    {
        $order = $this->order(['deposit_amount' => 20_00]);
        $this->notify($order, ['status' => 'success', 'invoiceId' => 'inv-dep', 'amount' => 20_00, 'messageId' => 'm-1']);
        $this->notify($order, ['status' => 'success', 'invoiceId' => 'inv-rest', 'amount' => 80_00, 'messageId' => 'm-2']);
        $deposit = Payment::where('external_id', 'inv-dep')->firstOrFail();

        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin)->get('http://localhost/admin/orders/'.$order->order_number)
            ->assertOk()->assertSee('data-payment="'.$deposit->id.'"', false);

        $this->post('http://localhost/admin/payments/orders/'.$order->order_number.'/refund', ['payment' => $deposit->id])
            ->assertRedirect();

        $this->assertSame('inv-dep', FakeGateway::$acted[0]['params']['external_id']);
        $this->assertSame(PaymentState::REFUNDED, $deposit->fresh()->state);
        $this->assertSame(80_00, $order->fresh()->paid_total);
    }

    public function test_the_nightly_check_asks_about_the_rest_of_a_partly_paid_order(): void
    {
        $order = $this->order(['deposit_amount' => 20_00]);
        $this->notify($order, ['status' => 'success', 'invoiceId' => 'inv-dep', 'amount' => 20_00, 'messageId' => 'm-1']);

        // The invoice for the rest, begun and not yet heard of.
        Transaction::create([
            'gateway' => 'fake', 'order_id' => $order->id, 'external_id' => 'inv-rest', 'state' => PaymentState::PENDING,
            'amount' => 80_00, 'verified' => true, 'applied' => false, 'created_at' => now(),
        ]);

        ReconcilePayments::dispatchSync();

        $this->assertSame(['refresh'], array_column(FakeGateway::$acted, 'capability'));
        $this->assertSame('inv-rest', FakeGateway::$acted[0]['params']['external_id']);
        $this->assertSame(100_00, $order->fresh()->paid_total);
    }

    public function test_a_payment_begun_under_one_name_and_reported_under_another_is_one_payment(): void
    {
        $order = $this->order();

        // What Stripe does: a Checkout session is begun, its PaymentIntent is
        // what the money arrives under.
        Transaction::create([
            'gateway' => 'fake', 'order_id' => $order->id, 'external_id' => 'cs_1', 'state' => PaymentState::PENDING,
            'amount' => 100_00, 'verified' => true, 'applied' => false, 'created_at' => now(),
        ]);
        $this->notify($order, ['status' => 'success', 'invoiceId' => 'pi_1', 'amount' => 100_00, 'messageId' => 'm-1']);

        $payments = Payment::where('order_id', $order->id)->get();
        $this->assertCount(1, $payments);
        $this->assertSame('pi_1', $payments[0]->external_id);
        $this->assertSame('cs_1', $payments[0]->started_as);
    }

    protected function notify(Order $order, array $body): void
    {
        $body['reference'] = $order->id;
        $json = json_encode($body);

        $this->call('POST', 'http://localhost/payment/fake/callback', [], [], [],
            ['HTTP_X-Signature' => hash_hmac('sha256', $json, $this->token), 'CONTENT_TYPE' => 'application/json'],
            $json,
        )->assertOk();
    }

    protected function order(array $attributes = []): Order
    {
        return Order::create($attributes + [
            'order_number' => 'TG-OP'.random_int(1000, 9999),
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test',
            'address_line' => '1 Test Street', 'city' => 'Testville',
            'currency_code' => 'USD', 'subtotal' => 100_00, 'shipping_cost' => 0, 'total' => 100_00,
            'status' => 'new', 'payment_method_name' => $this->method->t('name'), 'stock_deducted' => false,
            'items' => [['product_id' => $this->product->id, 'sku' => 'OP-1', 'name' => 'Sofa', 'qty' => 1, 'unit_price' => 100_00, 'line_total' => 100_00]],
        ])->fresh();
    }
}
