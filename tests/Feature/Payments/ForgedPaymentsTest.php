<?php

namespace Tests\Feature\Payments;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\ForgedPayments;
use Torgash\Payments\Models\Payment;
use Torgash\Payments\Models\Transaction;

/**
 * Taking away what unsigned callbacks left behind before they were stopped.
 *
 * This deletes money from the books, so most of these are about what it must
 * NOT touch: a payment is gone only when the row that made it was unverified
 * and nothing verified ever spoke for it.
 */
class ForgedPaymentsTest extends TestCase
{
    protected Product $product;

    protected PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->product = Product::create([
            'sku' => 'FORGED-1', 'name' => ['en' => 'Widget'], 'slug' => 'forged-widget',
            'price' => 50_00, 'stock' => 10, 'status' => 'active',
        ]);

        $this->method = PaymentMethod::where('enabled', true)->orderBy('id')->firstOrFail();
    }

    public function test_a_payment_an_unsigned_callback_made_goes_and_the_order_is_worked_out_again(): void
    {
        $order = $this->paidOrder();
        $this->invoice($order, 'inv-real');
        $forged = $this->forged($order, 'forged-1');

        $this->assertSame([$order->order_number], app(ForgedPayments::class)->clear());

        $this->assertNull(Payment::find($forged->id));
        $this->assertSame(['inv-real'], Payment::where('order_id', $order->id)->pluck('external_id')->all());

        $order->refresh();
        $this->assertSame(PaymentState::PENDING, $order->payment_state);
        $this->assertSame(0, (int) $order->paid_total);
        // Whether goods already left is for a person: the history says why.
        $this->assertSame('open', $order->status);
        $this->assertTrue($order->logs()->get()->contains(fn ($log) => str_contains($log->text(), 'forged-1')));
    }

    public function test_the_log_keeps_what_arrived_and_loses_what_could_be_believed(): void
    {
        $order = $this->paidOrder();
        $this->invoice($order, 'inv-real');
        $this->forged($order, 'forged-1');

        app(ForgedPayments::class)->clear();

        $kept = Transaction::where('verified', false)->firstOrFail();
        foreach (['order_id', 'payment_id', 'external_id', 'event_key', 'state', 'amount'] as $column) {
            $this->assertNull($kept->{$column}, $column);
        }
        $this->assertSame(['status' => 'success'], $kept->raw);

        $this->assertSame($order->id, Transaction::where('verified', true)->value('order_id'));
    }

    /** The log is swept after months: "nothing verified" alone would condemn old real payments. */
    public function test_a_real_payment_whose_log_was_swept_is_left_alone_even_if_an_unsigned_message_named_it(): void
    {
        $order = $this->paidOrder();
        $real = Payment::create([
            'order_id' => $order->id, 'gateway' => 'fake', 'external_id' => 'inv-old',
            'state' => PaymentState::PAID, 'requested' => 100_00, 'amount' => 100_00, 'created_at' => now()->subYear(),
        ]);
        Transaction::create([
            'gateway' => 'fake', 'order_id' => $order->id, 'payment_id' => $real->id, 'external_id' => 'inv-old',
            'state' => PaymentState::PAID, 'amount' => 100_00, 'verified' => false, 'applied' => false, 'created_at' => now(),
        ]);

        $this->assertSame([], app(ForgedPayments::class)->clear());

        $this->assertNotNull(Payment::find($real->id));
        $this->assertSame(PaymentState::PAID, $order->fresh()->payment_state);
    }

    public function test_a_payment_a_verified_message_spoke_for_is_left_alone(): void
    {
        $order = $this->paidOrder();
        $payment = $this->forged($order, 'forged-1');
        // A gateway that names no id: its news went to the latest payment.
        Transaction::create([
            'gateway' => 'fake', 'order_id' => $order->id, 'payment_id' => $payment->id,
            'state' => PaymentState::PAID, 'amount' => 100_00, 'verified' => true, 'applied' => true, 'created_at' => now(),
        ]);

        $this->assertSame([], app(ForgedPayments::class)->clear());

        $this->assertNotNull(Payment::find($payment->id));
    }

    public function test_the_update_runs_it(): void
    {
        $order = $this->paidOrder();
        $forged = $this->forged($order, 'forged-1');
        Log::spy();

        (require base_path('modules/torgash.payments/migrations/2026_11_09_000100_clear_unsigned_payment_callbacks.php'))->up();

        $this->assertNull(Payment::find($forged->id));
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => str_contains($message, (string) $order->order_number));
    }

    // ---------------------------------------------------------------- helpers

    /** Exactly what `Ledger::started` used to make of an unverified "paid". */
    protected function forged(Order $order, string $id): Payment
    {
        $at = now()->subDay();

        $payment = Payment::create([
            'order_id' => $order->id, 'gateway' => 'fake', 'external_id' => $id,
            'state' => PaymentState::PAID, 'requested' => 100_00, 'amount' => 100_00, 'created_at' => $at,
        ]);

        Transaction::create([
            'gateway' => 'fake', 'order_id' => $order->id, 'payment_id' => $payment->id, 'external_id' => $id,
            'event_key' => 'key-'.$id, 'state' => PaymentState::PAID, 'amount' => 100_00,
            'verified' => false, 'applied' => false, 'summary' => 'Signature did not verify — ignored',
            'raw' => ['status' => 'success'], 'created_at' => $at,
        ]);

        return $payment;
    }

    protected function invoice(Order $order, string $id): void
    {
        Transaction::create([
            'gateway' => 'fake', 'order_id' => $order->id, 'external_id' => $id,
            'state' => PaymentState::PENDING, 'amount' => 100_00,
            'verified' => true, 'applied' => false, 'created_at' => now()->subDays(2),
        ]);
    }

    protected function paidOrder(): Order
    {
        return Order::create([
            'order_number' => 'TG-FP'.random_int(10000, 99999),
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'currency_code' => 'USD',
            'subtotal' => 100_00,
            'shipping_cost' => 0,
            'total' => 100_00,
            'status' => 'open',
            'payment_state' => PaymentState::PAID,
            'paid_total' => 100_00,
            'paid_at' => now(),
            'payment_method_name' => $this->method->t('name'),
            'stock_deducted' => true,
            'items' => [[
                'product_id' => $this->product->id, 'sku' => $this->product->sku,
                'name' => 'Widget', 'qty' => 2, 'unit_price' => 50_00, 'line_total' => 100_00,
            ]],
        ])->fresh();
    }
}
