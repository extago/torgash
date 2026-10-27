<?php

namespace Torgash\Payments;

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

        $event = $found['gateway']->act($capability, $order, $found['binding']->gatewayConfig(), array_filter([
            'amount' => $amount,
            'reason' => $reason,
            'external_id' => $payment->external_id,
            'held' => $payment->held(),
        ], fn ($value) => $value !== null));

        if (! $event) {
            return null;
        }

        // A gateway asked about THIS payment answers about it, whatever id it
        // happens to put on the answer.
        $this->applier->apply($payment->gateway, $order, $event, true, $payment);

        return $event;
    }
}
