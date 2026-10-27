<?php

namespace Torgash\Checkout\Orders;

use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;

/**
 * The money moved, and nobody's gateway said so.
 *
 * A shop taking a bank transfer or cash on delivery settles orders by somebody
 * looking at a statement and saying "this one is paid". That has always lived
 * in the admin controller, which was honest while a person clicking a select
 * was the only way it could happen. It no longer is: an accountant marking the
 * transfer in 1C is the same act performed in a different window, and the
 * exchange must not grow its own copy of "and also stamp paid_at, and zero the
 * amount if the state means no money, and write the log".
 *
 * Distinct from torgash.payments' PaymentApplier, and not a duplicate of it:
 * that one arbitrates BETWEEN gateway events — deduplicating by event key,
 * refusing to move backwards, keeping a transaction log — because a gateway
 * sends the same news twice and out of order. A human, or an accounting system
 * a human drives, is the authority on what it says; there is nothing to
 * arbitrate. What both share is the shape of the row they leave behind.
 */
class RecordPayment
{
    /**
     * @param  int|null  $amount  minor units of the shop's own currency; null means "all of it"
     * @param  string|null  $source  who says so, for the log — null is the person clicking
     */
    public function record(Order $order, string $state, ?int $amount = null, ?string $source = null): Order
    {
        // States that mean "no money here" zero the amount rather than leaving a
        // stale one behind: an order marked failed after a paid one would
        // otherwise still read as if the shop were holding the cash. What was
        // paid in advance (a gift card) is not the person's to zero — it stays,
        // and Settlement says what the order then holds. The amount typed is
        // the whole of what the order holds, as the field beside it shows.
        $amount = PaymentState::holdsMoney($state) ? ($amount ?? $order->total) : 0;
        $was = $order->payment_state;

        $columns = Settlement::afterReport($order, $state, max(0, $amount - (int) $order->prepaid_total));
        $order->update($columns);
        $state = $columns['payment_state'];
        $amount = $columns['paid_total'];

        OrderLog::record($order, $source ? 'Payment: :from → :to (:amount) — :source' : 'Payment: :from → :to (:amount)', array_filter([
            'from' => OrderLog::word(PaymentState::labelKey($was)),
            'to' => OrderLog::word(PaymentState::labelKey($state)),
            'amount' => format_price($amount, $order->currency_code),
            'source' => $source ?: null,
        ], fn ($v) => $v !== null));

        return $order;
    }
}
