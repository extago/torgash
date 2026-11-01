<?php

namespace Torgash\Rma;

use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Rma\Models\ReturnRequest;

/**
 * May this order be returned, and how much of it is left to return.
 *
 * Three settings and not a fourth condition engine (§29.3/2): a list of order
 * statuses the shop ticks, an optional "only what we recorded as paid", and an
 * optional number of days. Three fields answer the question; an engine would
 * have to be explained.
 *
 * "Only paid" is OFF by default, and that is the owner's decision rather than a
 * default chosen for tidiness: plenty of shops watch payment in a CRM or in
 * their accounting system, and there this check refuses a perfectly lawful
 * return for an order the shop knows was paid.
 */
class Eligibility
{
    /** Null when a return may be asked for; otherwise why not, in the reader's language. */
    public static function refusal(Order $order): ?string
    {
        $statuses = (array) setting('torgash.rma', 'statuses', []);

        // Nothing ticked means the shop has not said which of its own statuses
        // means "the customer has it" — so nothing is offered. Silence is not
        // consent here: guessing would put a return button on an order that has
        // not shipped.
        if (! in_array($order->status, $statuses, true)) {
            return __('This order cannot be returned.');
        }

        if (setting('torgash.rma', 'paid_only', false) && ! PaymentState::holdsMoney($order->payment_state)) {
            return __('This order cannot be returned.');
        }

        $days = (int) setting('torgash.rma', 'days', 0);

        if ($days > 0 && $order->created_at?->addDays($days)->isPast()) {
            return __('The time for returning this order has passed.');
        }

        if (self::remaining($order) === []) {
            return __('Everything in this order has already been sent back.');
        }

        return null;
    }

    public static function allows(Order $order): bool
    {
        return self::refusal($order) === null;
    }

    /**
     * The order's lines, less what is already covered by a return that has not
     * been declined.
     *
     * Without this a customer can ask for the same thing five times and the
     * shop refunds it five times — the quantity a return may name has to be
     * bounded by what is actually still theirs to send back. A DECLINED return
     * frees its quantity again, because nothing was sent.
     *
     * @return list<array<string, mixed>> order lines with `qty` reduced
     */
    /**
     * What returning `$qty` more of a remaining line owes, in minor units: a
     * share of the line's TOTAL, never price × quantity — at $0.012 apiece
     * 333 pieces are 4.00 of a 12.00 line, and three such returns must come
     * to 12.00, not 11.99 or 12.01. Worked out as the difference of two
     * cumulative shares, so every return's part telescopes into exactly the
     * line's total by the time the last piece is back (PLAN §65).
     *
     * @param  array<string, mixed>  $line  a line of `remaining()`
     */
    public static function share(array $line, int $qty): int
    {
        $bought = max(1, (int) ($line['bought_qty'] ?? $line['qty'] ?? 1));
        $taken = (int) ($line['taken_qty'] ?? 0);
        $total = (int) ($line['line_total'] ?? price_scale()->amount((int) ($line['unit_price'] ?? 0) * $bought));
        $upTo = fn (int $n) => (int) round($total * min($n, $bought) / $bought);

        return $upTo($taken + $qty) - $upTo($taken);
    }

    public static function remaining(Order $order): array
    {
        $claimed = [];

        foreach (ReturnRequest::where('order_id', $order->id)->where('state', '!=', State::DECLINED)->get() as $request) {
            foreach ($request->lines as $line) {
                $id = (int) ($line['product_id'] ?? 0);
                $claimed[$id] = ($claimed[$id] ?? 0) + (int) ($line['qty'] ?? 0);
            }
        }

        $remaining = [];

        // What has been claimed is CONSUMED line by line rather than
        // subtracted from each: since a product can be configured, one product
        // can sit on two lines of an order (a processor inside a server and the
        // same processor on its own), and subtracting the whole claim from both
        // would refuse a return of goods nobody has asked back yet.
        foreach ((array) $order->items as $line) {
            $id = (int) ($line['product_id'] ?? 0);
            $qty = (int) ($line['qty'] ?? 0);
            $take = min($qty, $claimed[$id] ?? 0);
            $claimed[$id] = ($claimed[$id] ?? 0) - $take;
            $qty -= $take;

            if ($qty > 0) {
                // How many the line had and how many earlier returns took:
                // what a return OWES is worked out from the line's total
                // with them (`share()`), so the parts add up to that total.
                $remaining[] = ['qty' => $qty, 'bought_qty' => (int) ($line['qty'] ?? 0), 'taken_qty' => $take] + $line;
            }
        }

        return $remaining;
    }
}
