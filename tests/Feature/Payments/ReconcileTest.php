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
use Torgash\Payments\Jobs\ReconcilePayments;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Models\Transaction;

/**
 * Asking the gateway about payments we are still waiting on.
 *
 * A callback is the fast path, not the only one: it gets lost, and on a
 * development machine the bank cannot reach the shop at all. What this has to
 * get right is WHICH orders it asks about — an unpaid order with no invoice, a
 * settled one, or one from last month must not each cost an API call every
 * fifteen minutes.
 */
class ReconcileTest extends TestCase
{
    protected Product $product;

    protected PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        FakeGateway::reset();

        hook_listen('payments.gateways', fn (array $g) => $g + ['fake' => FakeGateway::class]);

        $this->product = Product::create([
            'sku' => 'REC-1', 'name' => ['en' => 'Widget'], 'slug' => 'reconcile-widget',
            'price' => 50_00, 'stock' => 10, 'status' => 'active',
        ]);

        $this->method = PaymentMethod::where('enabled', true)->orderBy('id')->firstOrFail();

        Binding::create([
            'payment_method_id' => $this->method->id,
            'gateway' => 'fake',
            'config' => ['token' => 't'],
        ]);
    }

    public function test_a_payment_the_callback_never_told_us_about_is_found(): void
    {
        // The order the local machine can never learn about: the bank took the
        // money and called an address it cannot reach.
        $order = $this->order();
        $this->invoice($order);

        ReconcilePayments::dispatchSync();

        $order->refresh();

        $this->assertSame(PaymentState::PAID, $order->payment_state);
        $this->assertSame(100_00, $order->paid_total);
    }

    public function test_nothing_pending_starts_no_task(): void
    {
        // Only orders nobody would ask a bank about: the scheduler's pass
        // must not leave a "checked 0" task every fifteen minutes.
        $this->order();
        $this->invoice($this->order(), at: now()->subDays(30));

        $this->assertFalse(ReconcilePayments::anyPending());

        $this->invoice($this->order());

        $this->assertTrue(ReconcilePayments::anyPending());
    }

    public function test_it_only_asks_about_orders_that_are_worth_asking_about(): void
    {
        // Has an invoice and is waiting — asked.
        $waiting = $this->order();
        $this->invoice($waiting);

        // Already settled — nothing to ask.
        $settled = $this->order(['payment_state' => PaymentState::PAID, 'paid_total' => 100_00]);
        $this->invoice($settled);

        // No invoice at all: paid by hand, cash on delivery, a method with no
        // gateway. Asking would be one API call per order for ever.
        $manual = $this->order();

        // Old enough that an unpaid invoice is not going to be paid.
        $stale = $this->order();
        $this->invoice($stale, at: now()->subDays(30));

        ReconcilePayments::dispatchSync();

        $this->assertSame(PaymentState::PAID, $waiting->fresh()->payment_state);
        $this->assertSame(PaymentState::PENDING, $manual->fresh()->payment_state);
        $this->assertSame(PaymentState::PENDING, $stale->fresh()->payment_state);
    }

    public function test_an_answer_that_would_move_the_order_backwards_is_still_refused(): void
    {
        // Same applier as the callback, so the ordering rule holds here too:
        // a payment already partly refunded is not walked back to paid.
        $order = $this->order(['payment_state' => PaymentState::PARTIALLY_REFUNDED, 'paid_total' => 40_00]);
        $this->invoice($order, state: PaymentState::PARTIALLY_REFUNDED);

        ReconcilePayments::dispatchSync();

        $order->refresh();

        $this->assertSame(PaymentState::PARTIALLY_REFUNDED, $order->payment_state);
        $this->assertSame(40_00, $order->paid_total);
    }

    public function test_an_admin_can_ask_now_and_it_goes_to_the_queue(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/payments/reconcile')
            ->assertRedirect();

        // Queued, not run inline: one press must not sit there making an API
        // call per order while the admin waits.
        $this->assertDatabaseHas('background_tasks', ['name' => 'Check pending payments']);
    }

    // ---------------------------------------------------------------- helpers

    protected function invoice(Order $order, $at = null, string $state = PaymentState::PENDING): void
    {
        Transaction::create([
            'gateway' => 'fake',
            'order_id' => $order->id,
            'external_id' => 'inv-'.$order->id,
            'state' => $state,
            'verified' => true,
            'applied' => false,
            'created_at' => $at ?? now(),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TG-RC'.random_int(10000, 99999),
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
                'product_id' => $this->product->id, 'sku' => $this->product->sku,
                'name' => 'Widget', 'qty' => 2, 'unit_price' => 50_00, 'line_total' => 100_00,
            ]],
        ], $overrides))->fresh();
    }
}
