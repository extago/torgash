<?php

namespace Torgash\Payments;

use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Orders\OrderStatusChanger;
use Torgash\Checkout\Orders\OrderStock;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Checkout\Orders\Settlement;
use Torgash\Payments\Models\Payment;
use Torgash\Payments\Models\Transaction;

/**
 * Move the order's payment state from what a gateway said — once, and never
 * backwards.
 *
 * Two failure modes this exists to prevent, both taken from a real add-on that
 * has neither:
 *
 * 1. **Redelivery.** A gateway that gets no 200 sends again. Applying the same
 *    message twice is a second payment in the log and, with a partial refund in
 *    it, a wrong `paid_total`. So an event carrying the gateway's own message id
 *    is recorded first, under a unique key: the second arrival is stored as a
 *    duplicate and applied to nothing.
 *
 * 2. **Order.** Callbacks arrive out of order — a `processing` delayed behind
 *    the `success` that followed it. Applied naively, the order goes from paid
 *    back to awaiting payment, and (in the add-on, where gateway statuses are
 *    mapped onto ORDER statuses) a shipped order jumps back to "new". So states
 *    are ranked, and a late one that would move backwards is recorded and
 *    ignored — except a refund or a reversal, which are genuinely later news
 *    than a payment.
 */
class PaymentApplier
{
    public function __construct(protected Ledger $ledger) {}

    /**
     * How far along the payment is. Only used to refuse going backwards, so the
     * exact numbers matter less than the order.
     */
    protected const RANK = [
        PaymentState::PENDING => 0,
        PaymentState::FAILED => 1,
        PaymentState::EXPIRED => 1,
        PaymentState::AUTHORIZED => 2,
        PaymentState::PAID => 3,
    ];

    /** News that is always later than a payment, whatever the ranks say. */
    protected const TERMINAL = [PaymentState::PARTIALLY_REFUNDED, PaymentState::REFUNDED];

    /**
     * Record the event and apply it if it should be. Returns the transaction —
     * `applied` on it says whether the order moved.
     */
    public function apply(string $gateway, Order $order, PaymentEvent $event, bool $verified = true, ?Payment $about = null): Transaction
    {
        // News about an order poured into another is news about the one it
        // went into: that is where its money is now (PLAN §40.10/4). The
        // payment keeps the order the bank knows it by.
        $order = $order->mergeRoot();

        $duplicate = $event->eventKey !== null && Transaction::where('gateway', $gateway)
            ->where('event_key', $event->eventKey)
            ->exists();

        // An event speaks about ONE payment, and it is that payment's own
        // story that must not go backwards: the order being partly paid by a
        // deposit says nothing about whether the invoice for the rest has
        // moved (PLAN §40.10/4). An unverified message is recorded against
        // nothing.
        // A button asked about one payment names it (`$about`): the answer is
        // about that payment whatever id the gateway writes on it.
        $payment = $verified ? ($about ?? $this->ledger->forEvent($gateway, $order, $event)) : null;

        $shouldApply = $verified && ! $duplicate && $this->isForward($payment?->state, $event->state);

        // A gateway reports in the currency it was asked to charge; this order —
        // `total`, `paid_total` — and this log are in the shop's own. Without
        // the conversion a ₴42 732 payment against a $1 042.25 order reads as a
        // fortyfold overpayment, and the order screen says the shop owes money
        // back. The gateway's own number stays in `raw`.
        $reported = $event->amount === null ? null : $order->baseFromCharge($event->amount);

        $transaction = Transaction::create([
            'gateway' => $gateway,
            'order_id' => $order->id,
            'payment_id' => $payment?->id,
            'external_id' => $event->externalId,
            // The key is dropped on a duplicate rather than colliding: the point
            // is to keep the evidence that it arrived twice, not to refuse it.
            'event_key' => $duplicate ? null : $event->eventKey,
            'state' => $event->state,
            'amount' => $reported,
            'verified' => $verified,
            'applied' => $shouldApply,
            'summary' => $this->summary($event, $verified, $duplicate, $shouldApply),
            'raw' => $event->raw,
            'created_at' => now(),
        ]);

        if (! $shouldApply) {
            return $transaction;
        }

        $was = $order->payment_state;

        // What this payment holds now. A gateway that names no amount holds
        // what it held, else what it was asked for.
        $payment->update([
            'state' => $event->state,
            'amount' => PaymentState::holdsMoney($event->state)
                ? ($reported ?? ($payment->amount ?: $payment->requested ?: $order->chargeNow()))
                : 0,
        ]);

        // The order's gateway part is ALL its payments, and its state is read
        // from all of them; the prepaid part stays whatever the bank says
        // (Settlement).
        $payments = $this->ledger->of($order);
        $columns = Settlement::afterReport($order, $this->ledger->state($payments, $payment), $this->ledger->held($payments));
        $order->update($columns);

        OrderLog::record($order, ':gateway: :from → :to', [
            'gateway' => $gateway,
            'from' => OrderLog::word(PaymentState::labelKey($was)),
            'to' => OrderLog::word(PaymentState::labelKey($columns['payment_state'])),
        ], by: null);

        $this->settle($order, $columns['payment_state']);

        return $transaction;
    }

    /**
     * An order waiting to be paid online is not holding any stock — that is the
     * whole point of the status it was placed in. The money arriving is what
     * turns it into an ordinary order, and this is the moment the goods come off
     * the shelf.
     *
     * Through OrderStatusChanger, the same funnel the admin's own status select
     * uses: it takes the stock, records the change and lets the customer's email
     * go out, once, from one place. An order in any other status is left alone —
     * a payment arriving for an order somebody already moved to Processing must
     * not drag it backwards.
     *
     * When the shelf can no longer cover it, the order goes to Backordered
     * instead: the customer has paid and the shop cannot ship, which is a state
     * a human has to see rather than a warehouse quietly going negative. Same
     * answer older engines give at the same moment.
     */
    protected function settle(Order $order, string $state): void
    {
        if ($order->status !== OrderStatus::AWAITING_PAYMENT || ! PaymentState::holdsMoney($state)) {
            return;
        }

        $covered = app(OrderStock::class)->canCover($order->items ?? []);
        $target = ($covered ? null : OrderStatus::find('backordered')) ?? OrderStatus::default();

        app(OrderStatusChanger::class)->change($order, $target);
    }

    /**
     * A refund is always applicable; anything else must not lower the rank.
     * Equal ranks pass, because "paid 100 then paid 150" is a correction, not a
     * step backwards.
     */
    protected function isForward(?string $from, string $to): bool
    {
        if (in_array($to, self::TERMINAL, true)) {
            return true;
        }

        if (in_array($from, self::TERMINAL, true)) {
            return false;
        }

        return (self::RANK[$to] ?? 0) >= (self::RANK[$from] ?? 0);
    }

    protected function summary(PaymentEvent $event, bool $verified, bool $duplicate, bool $applied): string
    {
        if (! $verified) {
            return __('Signature did not verify — ignored');
        }

        if ($duplicate) {
            return __('Already seen — ignored');
        }

        if (! $applied) {
            return __('Out of order — ignored');
        }

        return $event->summary ?? PaymentState::label($event->state);
    }
}
