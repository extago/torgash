<?php

namespace Torgash\Checkout;

use Torgash\Cart\CartManager;
use Torgash\Checkout\Shipping\Quote;

/**
 * The bottom of the bill: what other modules add to it, and the grand total.
 *
 * Extracted from the checkout controller because the summary is now rendered
 * from three places (the form, the fragment a shipping change swaps in, the
 * order being written), and a total computed twice is a total that eventually
 * disagrees with itself.
 */
class Totals
{
    /**
     * Lines contributed by other modules — today the taxes module, which is
     * why checkout knows nothing about tax itself.
     *
     * A line is ['code', 'label', 'amount', 'included']. An INCLUDED line is
     * already part of the prices being summed and is shown for information
     * only ("of which VAT ..."); everything else is added to the total. Getting
     * that wrong charges the customer their VAT twice, so it is a flag on the
     * line rather than something each caller decides.
     *
     * `$paymentMethodId` is the caller's own current answer to "which one is
     * chosen" — checkout has no state of its own to read it back from, since a
     * live cart page has no order yet. Optional: a caller with nothing to say
     * (the cart page, before checkout) passes null and a payment-conditioned
     * line simply does not resolve yet, the same as a shipping-conditioned one
     * before a destination is chosen.
     *
     * @return list<array{code: string, label: string, amount: int, included: bool}>
     */
    public function extra(CartManager $cart, Quote $quote, ?string $paymentMethodId = null): array
    {
        return hook_filter('checkout.extra_totals', [], [
            'cart' => $cart,
            'subtotal' => $quote->subtotal,
            'discount' => $quote->discount,
            'shipping_cost' => $quote->shippingCost(),
            'payment_method_id' => $paymentMethodId,
            // Where the goods are going, as delivery was quoted for it — a tax
            // owed by destination reads it rather than guessing its own.
            'location' => $quote->location,
        ]);
    }

    /**
     * What will be paid before any gateway is asked, line by line — a gift
     * card on the basket (PLAN §40.4). Asked of whoever holds such money
     * (`checkout.prepaid.quote`), never more in total than the bill itself.
     * Checkout does not know what a gift card is; it knows that part of a
     * bill can already be paid, which is what the summary and the placement
     * need.
     *
     * @return list<array{label: string, amount: int}>
     */
    public function prepaidLines(CartManager $cart, int $total, int $shippingCost): array
    {
        $lines = hook_filter('checkout.prepaid.quote', [], [
            'cart' => $cart,
            'total' => $total,
            'shipping_cost' => $shippingCost,
        ]);

        $left = max(0, $total);
        $kept = [];

        foreach (is_array($lines) ? $lines : [] as $line) {
            $amount = min($left, (int) ($line['amount'] ?? 0));

            if (! is_array($line) || $amount <= 0) {
                continue;
            }

            $kept[] = ['label' => (string) ($line['label'] ?? ''), 'amount' => $amount] + $line;
            $left -= $amount;
        }

        return $kept;
    }

    /** The sum of prepaidLines(). */
    public function prepaid(CartManager $cart, int $total, int $shippingCost): int
    {
        return array_sum(array_column($this->prepaidLines($cart, $total, $shippingCost), 'amount'));
    }

    /** @param  list<array{amount: int, included: bool}>  $lines */
    public function added(array $lines): int
    {
        return array_sum(array_map(
            fn (array $line) => $line['included'] ? 0 : $line['amount'],
            $lines,
        ));
    }

    /** @param  list<array{amount: int, included: bool}>  $lines  from extra(), so it is summed once */
    public function grand(Quote $quote, array $lines): int
    {
        return $quote->payable() + $quote->shippingCost() + $this->added($lines);
    }
}
