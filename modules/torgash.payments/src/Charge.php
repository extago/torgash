<?php

namespace Torgash\Payments;

use Torgash\Checkout\Models\Order;

/**
 * What to ask the customer's bank for: an amount, in a currency.
 *
 * Handed to the gateway rather than left for it to work out, because every
 * gateway would work it out the same way and one of them would work it out
 * differently. The shop keeps its books in its own currency; the bank is asked
 * for the order's payment currency — the one the method takes, which is not
 * always the one the customer was quoted in — at the rate frozen onto the
 * order, and turning the first into the second is arithmetic the module owns.
 *
 * `amount` is in hundredths of `currency`, which is the unit every acquiring
 * met so far bills in (monobank's kopecks, Stripe's cents). A zero-decimal
 * currency like the yen would need this revisited; none of the gateways here
 * takes one.
 */
final class Charge
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {}

    /**
     * The whole order, or a part of it (a partial refund), in the currency the
     * BANK is asked for.
     *
     * Both halves must come from the same axis, and once they did not: the
     * amount was converted into the payment currency while the code beside it
     * stayed the one the customer was quoted in. That is 42 702 hryvnia handed
     * to an acquirer labelled "USD" — and the only reason it did not become a
     * $42 702 charge is that monobank happened to refuse the request for an
     * unrelated reason (it holds money only in hryvnia). A currency and an
     * amount are one fact; they are read from one place.
     */
    public static function for(Order $order, ?int $baseAmount = null): self
    {
        // The whole order means what is left of it after a gift card — the bank
        // never sees the prepaid part (PLAN §40.4) — or only its deposit, for
        // an order sold "part now, the rest on delivery" (§40.10/1).
        return new self($order->chargeMinor($baseAmount ?? $order->chargeNow()), $order->paymentCurrency());
    }

    /** What this amount is worth in the shop's own books. */
    public function toBase(Order $order): int
    {
        return $order->baseFromCharge($this->amount);
    }
}
