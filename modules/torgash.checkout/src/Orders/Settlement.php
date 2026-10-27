<?php

namespace Torgash\Checkout\Orders;

use Torgash\Checkout\Models\Order;

/**
 * The payment columns of an order, worked out from its TWO kinds of money
 * (PLAN §40.4): what was paid in advance (`prepaid_total` — a gift card) and
 * what a gateway, a courier or a transfer brought.
 *
 * `paid_total` holds both, because "how much of this order is paid" is one
 * question and every screen, report and exchange already asks it of that
 * column. What needed an answer was the state: a gateway reporting "failed"
 * about ITS part must not make an order whose gift card covered half read as
 * holding nothing, and a gateway's "paid" for the remainder is the whole order
 * paid. One class, because three callers write these columns — a gateway's
 * event (PaymentApplier), a person or 1C (RecordPayment) and a gift card
 * arriving or leaving — and three copies of the rule is how an order comes to
 * say "refunded" while the shop still holds a gift card's money.
 */
class Settlement
{
    /**
     * After somebody says what happened to the NON-prepaid part: `$state`
     * about it, `$amount` of it held (ignored for a state that holds nothing).
     *
     * @return array{payment_state: string, paid_total: int, paid_at: mixed}
     */
    public static function afterReport(Order $order, string $state, int $amount): array
    {
        $prepaid = (int) $order->prepaid_total;
        $amount = PaymentState::holdsMoney($state) ? max(0, $amount) : 0;
        $paid = $prepaid + $amount;

        // A deposit arriving is "some, not all" by design: the rest is the
        // courier's (PLAN §40.10/1). Said only for an order sold that way, so a
        // gateway that underpays an ordinary order still reads as it always
        // did — paid, with the balance showing the gap.
        if ($order->deposit_amount && $state === PaymentState::PAID && $paid < (int) $order->total) {
            $state = PaymentState::PARTIALLY_PAID;
        }

        if ($prepaid > 0 && ! PaymentState::holdsMoney($state)) {
            // The gateway's news is about its own part; the gift card's money
            // is still here, so the order holds SOMETHING whatever it said.
            $state = match (true) {
                $state === PaymentState::REFUNDED => PaymentState::PARTIALLY_REFUNDED,
                $paid >= (int) $order->total => PaymentState::PAID,
                default => PaymentState::PARTIALLY_PAID,
            };
        }

        return static::columns($order, $state, $paid);
    }

    /**
     * After the prepaid part itself moved — a gift card applied to the order
     * or taken back off it. The gateway's part is left exactly as it was.
     *
     * @return array{payment_state: string, paid_total: int, paid_at: mixed, prepaid_total: int}
     */
    public static function afterPrepaid(Order $order, int $prepaid): array
    {
        $gatewayPart = $order->gatewayPaid();
        $prepaid = max(0, $prepaid);
        $paid = $prepaid + $gatewayPart;
        $current = (string) $order->payment_state;

        $state = match (true) {
            // A hold or a refund is the gateway's own story, and a gift card
            // arriving beside it does not change what the bank is doing.
            in_array($current, [PaymentState::AUTHORIZED, PaymentState::PARTIALLY_REFUNDED, PaymentState::REFUNDED], true) && $gatewayPart > 0 => $current,
            $paid <= 0 => in_array($current, [PaymentState::FAILED, PaymentState::EXPIRED, PaymentState::REFUNDED], true) ? $current : PaymentState::PENDING,
            $paid >= (int) $order->total => PaymentState::PAID,
            default => PaymentState::PARTIALLY_PAID,
        };

        return static::columns($order, $state, $paid) + ['prepaid_total' => $prepaid];
    }

    /** @return array{payment_state: string, paid_total: int, paid_at: mixed} */
    protected static function columns(Order $order, string $state, int $paid): array
    {
        return [
            'payment_state' => $state,
            'paid_total' => $paid,
            // Stamped on the first money and not moved by later edits: a refund
            // window is counted from when the money arrived.
            'paid_at' => $paid > 0 ? ($order->paid_at ?? now()) : null,
        ];
    }
}
