<?php

namespace Torgash\Payments;

use Illuminate\Support\Facades\Cache;
use Torgash\Checkout\Models\Order;
use Torgash\Payments\Models\Payment;

/**
 * Doing something to ONE payment at its gateway — re-ask, capture, refund,
 * release, cancel — and applying what the gateway answers through the same
 * applier a callback goes through.
 *
 * The gateway is handed the payment's own id (`external_id`) and what it holds
 * now (`held`, the shop's books), because an order with a deposit and its rest
 * has two invoices, and "the order's latest invoice" is how a refund used to
 * reach only the second. The payment is acted on through the order it was MADE
 * for: that is the order the bank knows it by, and the payment method there is
 * the one whose credentials took it.
 */
class PaymentActions
{
    /** Seconds in which the same act on the same payment for the same sum is the same click. */
    protected const SAME_CLICK = 15;

    public function __construct(protected Gateways $gateways, protected PaymentApplier $applier) {}

    /** What can be done to this payment now, from what its gateway can do at all. */
    public function available(Payment $payment): array
    {
        $found = $this->gateways->forOrder($payment->order);

        if (! $found || $found['binding']->gateway !== $payment->gateway) {
            return [];
        }

        return array_values(array_filter($found['gateway']->capabilities(), fn (string $capability) => match ($capability) {
            'capture', 'void' => $payment->isHold(),
            'refund' => $payment->held() > 0,
            'cancel' => $payment->isOpen(),
            default => true,
        }));
    }

    /**
     * @return PaymentEvent|null the gateway's answer, already applied; null
     *                           when it could not or said nothing useful
     */
    public function act(Payment $payment, string $capability, ?int $amount = null, ?string $reason = null): ?PaymentEvent
    {
        /** @var Order $order */
        $order = $payment->order;
        $found = $this->gateways->forOrder($order);

        if (! $found || $found['binding']->gateway !== $payment->gateway
            || ! in_array($capability, $found['gateway']->capabilities(), true)) {
            return null;
        }

        // A button pressed twice is two requests, each of which finds the money
        // still there, and the bank will do a partial refund as often as it is
        // asked. Asking again what has happened costs nothing; everything else
        // moves money or ends a payment.
        if ($capability !== 'refresh' && ! Cache::add('payments.act:'.$payment->id.':'.$capability.':'.$amount, true, self::SAME_CLICK)) {
            return null;
        }

        $event = $found['gateway']->act($capability, $order, $found['binding']->gatewayConfig(), array_filter([
            'amount' => $amount,
            'reason' => $reason,
            'external_id' => $payment->external_id,
            'held' => $payment->held(),
        ], fn ($value) => $value !== null));

        if (! $event) {
            return null;
        }

        // Taking part of a hold is the shop asking for less: the bank's "paid"
        // for that part is the whole of what the invoice now wants, here and
        // in the callback that follows it.
        if ($capability === 'capture' && $amount !== null) {
            $payment->update(['requested' => $amount]);
        }

        // A gateway asked about THIS payment answers about it, whatever id it
        // happens to put on the answer.
        $this->applier->apply($payment->gateway, $order, $event, $payment);

        return $event;
    }
}
