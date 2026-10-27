<?php

namespace Torgash\Checkout\Orders;

use Torgash\Checkout\Models\Order;

/**
 * Giving money back — through the gateway that took it where there is one, and
 * by hand where there is not.
 *
 * This exists for the same reason RecordPayment does, one floor up: refunding
 * is not a returns concept. A manager refunds a cancelled order, an overcharge
 * or a goodwill gesture with no return in sight, and torgash.rma pressing its
 * own copy of "ask the gateway, else stamp the column" would be the second
 * implementation of the one thing this project keeps insisting has exactly one.
 *
 * The routing decision is deliberately NOT taken here: this asks
 * `checkout.order.refund` and torgash.payments answers it, because whether the
 * right act is a transfer or the release of a hold is a question about the
 * gateway's API and nothing checkout can see. Checkout only knows what to do
 * when nobody answers — record it, exactly as it records a bank transfer
 * arriving.
 *
 * **A hold is released, never transferred** (the owner's rule, §29.4/5): money
 * that was only ring-fenced has never left the customer's card, and sending
 * them a payment for it would take real money out of the shop and leave the
 * authorization to expire in their favour. The gateway module is where that
 * choice is made because only it knows which of its calls does which.
 */
class Refund
{
    public function __construct(protected RecordPayment $manual) {}

    /**
     * @param  int  $amount  minor units of the shop's books; more than the shop
     *                       is holding is refused rather than clamped — a manager typing 5000
     *                       instead of 500 must be told, not quietly obeyed with a different
     *                       number.
     * @param  string|null  $source  who says so, for the order log
     * @param  int|null  $toPrepaid  how much of it goes back where it was paid
     *                               in advance (a gift card); null = as much as
     *                               can, which is the owner's rule (§40.4): back
     *                               onto the card first — no bank fee, and no
     *                               cash leaves the shop — the rest to the bank
     */
    public function refund(Order $order, int $amount, ?string $source = null, ?int $toPrepaid = null): RefundResult
    {
        $held = (int) $order->paid_total;

        if ($amount <= 0) {
            return new RefundResult(false, __('Nothing to refund.'));
        }

        // The shop is not holding this person's money as far as this system
        // knows — which is the ordinary case in a shop that watches payment in
        // its CRM (the same reason "only paid orders" is off by default in the
        // returns settings). Saying so is the honest answer; stamping a refund
        // against a payment that was never recorded would invent one.
        if ($held <= 0) {
            return new RefundResult(false, __('This order is not recorded as paid here, so there is nothing to give back through the shop. Refund it wherever the money actually is.'));
        }

        if ($amount > $held) {
            return new RefundResult(false, __('That is more than the :amount recorded against this order.', [
                'amount' => format_price($held, $order->currency_code),
            ]));
        }

        // Read before the prepaid part moves the state: whether the BANK is
        // holding rather than charging is a fact about its part alone.
        $hold = $order->payment_state === PaymentState::AUTHORIZED;
        $prepaidPart = min($amount, (int) $order->prepaid_total, $toPrepaid ?? PHP_INT_MAX);
        $said = [];

        if ($prepaidPart > 0) {
            $said[] = $this->returnPrepaid($order, $prepaidPart, $source);
            $amount -= $prepaidPart;

            if ($amount <= 0) {
                return new RefundResult(true, implode(' ', $said));
            }
        }

        $summary = hook_filter('checkout.order.refund', null, [
            'order' => $order,
            'amount' => $amount,
            // A hold has not been taken yet, so it is released rather than
            // repaid. Passed as a fact about the order, not as an instruction:
            // the gateway module decides what to call.
            'hold' => $hold,
            'source' => $source,
        ]);

        if (is_string($summary) && $summary !== '') {
            // The gateway moved the payment state itself, through the applier
            // every callback goes through — so this must not also stamp it.
            return new RefundResult(true, trim(implode(' ', $said).' '.$summary), true);
        }

        $remaining = (int) $order->paid_total - $amount;

        $this->manual->record(
            $order,
            $remaining > 0 ? PaymentState::PARTIALLY_REFUNDED : PaymentState::REFUNDED,
            $remaining,
            $source,
        );

        $said[] = __('Refunded :amount.', [
            'amount' => format_price($amount, $order->currency_code),
        ]);

        return new RefundResult(true, implode(' ', $said));
    }

    /**
     * Giving back what was paid in advance — asked of whoever took it
     * (`checkout.order.refund_prepaid`, torgash.giftcards puts it back on the
     * cards). Nobody answering means the module that took it has gone; the
     * money is then recorded as given back by hand, exactly as a transfer is
     * when no gateway answers.
     */
    protected function returnPrepaid(Order $order, int $amount, ?string $source): string
    {
        $answer = hook_filter('checkout.order.refund_prepaid', null, [
            'order' => $order,
            'amount' => $amount,
            'source' => $source,
        ]);

        $order->refresh();
        $paid = max(0, (int) $order->paid_total - $amount);

        $order->update([
            'prepaid_total' => max(0, (int) $order->prepaid_total - $amount),
            'paid_total' => $paid,
            // A hold on the rest is still a hold; anything else that is left
            // is what a partial refund leaves.
            'payment_state' => match (true) {
                $paid <= 0 => PaymentState::REFUNDED,
                $order->payment_state === PaymentState::AUTHORIZED => PaymentState::AUTHORIZED,
                default => PaymentState::PARTIALLY_REFUNDED,
            },
            'paid_at' => $paid > 0 ? $order->paid_at : null,
        ]);

        \Torgash\Checkout\Models\OrderLog::record($order, 'Refunded :amount of what was paid in advance', [
            'amount' => format_price($amount, $order->currency_code),
        ]);

        return is_string($answer) && $answer !== ''
            ? $answer
            : __('Refunded :amount.', ['amount' => format_price($amount, $order->currency_code)]);
    }
}
