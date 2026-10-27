<?php

namespace Tests\Feature\Checkout;

use App\Core\Settings\SettingsManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Orders\CancelStaleOrders;
use Torgash\Checkout\Orders\PaymentState;

/**
 * An order sent to an online gateway and never paid sits in `awaiting_payment`
 * holding nothing (UnpaidStockTest). ReconcilePayments only chases the ones
 * that got an invoice; the rest are swept here, on a schedule, into a status
 * the shop chose — off by default, and only ever a status change.
 */
class CancelStaleOrdersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        // Off unless a test opts in, and back to the manifest default after.
        $this->set('cancel_unpaid_after_hours', 0);
    }

    public function test_a_stale_unpaid_order_is_cancelled_into_the_chosen_status(): void
    {
        $this->set('cancel_unpaid_after_hours', 24);
        $order = $this->awaitingOrder(agedHours: 50);

        $cancelled = app(CancelStaleOrders::class)->run();

        $this->assertSame(1, $cancelled);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertTrue(
            OrderLog::where('order_id', $order->id)->get()->contains(fn ($log) => $log->text() === 'Auto-cancelled: unpaid for 24 h'),
        );
    }

    public function test_the_target_status_is_a_setting(): void
    {
        $this->set('cancel_unpaid_after_hours', 24);
        $this->set('cancel_unpaid_status', 'failed');
        $order = $this->awaitingOrder(agedHours: 50);

        app(CancelStaleOrders::class)->run();

        $this->assertSame('failed', $order->fresh()->status);
    }

    public function test_an_order_that_has_not_waited_long_enough_is_left_alone(): void
    {
        $this->set('cancel_unpaid_after_hours', 24);
        $order = $this->awaitingOrder(agedHours: 2);

        $this->assertSame(0, app(CancelStaleOrders::class)->run());
        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->fresh()->status);
    }

    public function test_zero_hours_turns_the_sweep_off(): void
    {
        // The manifest default — left as set in setUp().
        $order = $this->awaitingOrder(agedHours: 500);

        $this->assertSame(0, app(CancelStaleOrders::class)->run());
        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->fresh()->status);
    }

    public function test_an_order_the_money_reached_is_never_swept(): void
    {
        $this->set('cancel_unpaid_after_hours', 24);
        // A two-phase hold: the funds are ring-fenced, the manager still has to
        // capture them.
        $order = $this->awaitingOrder(agedHours: 50, paymentState: PaymentState::AUTHORIZED);

        app(CancelStaleOrders::class)->run();

        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->fresh()->status);
    }

    public function test_only_awaiting_payment_orders_are_swept(): void
    {
        $this->set('cancel_unpaid_after_hours', 24);
        $order = $this->awaitingOrder(agedHours: 50);
        $order->forceFill(['status' => 'open'])->saveQuietly();

        $this->assertSame(0, app(CancelStaleOrders::class)->run());
        $this->assertSame('open', $order->fresh()->status);
    }

    public function test_a_deleted_target_status_is_a_no_op_rather_than_a_guess(): void
    {
        $this->set('cancel_unpaid_after_hours', 24);
        $this->set('cancel_unpaid_status', 'no-such-status');
        $order = $this->awaitingOrder(agedHours: 50);

        $this->assertSame(0, app(CancelStaleOrders::class)->run());
        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->fresh()->status);
    }

    public function test_the_sweep_is_registered_on_the_scheduler(): void
    {
        $schedule = new Schedule;
        hook_action('core.schedule', ['schedule' => $schedule]);

        $names = array_map(fn ($event) => (string) $event->description, $schedule->events());

        $this->assertContains('checkout:cancel-stale-orders', $names);
    }

    // ---------------------------------------------------------------- helpers

    protected function set(string $key, string|int $value): void
    {
        app(SettingsManager::class)->set('torgash.checkout', $key, $value);
    }

    protected function awaitingOrder(int $agedHours, string $paymentState = PaymentState::PENDING): Order
    {
        $order = Order::create([
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'subtotal' => 50_00,
            'total' => 50_00,
            'items' => [],
            'status' => OrderStatus::AWAITING_PAYMENT,
            'payment_state' => $paymentState,
        ]);

        // Straight to the column: age is the whole point, and going through the
        // model would stamp updated_at and fire the change funnel.
        Order::where('id', $order->id)->update(['created_at' => now()->subHours($agedHours)]);

        return $order;
    }
}
