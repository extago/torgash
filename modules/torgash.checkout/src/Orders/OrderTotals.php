<?php

namespace Torgash\Checkout\Orders;

use Torgash\Checkout\Models\Order;

/**
 * The one place a PLACED order's money is recomputed.
 *
 * The formula is the same one CheckoutController writes at checkout time —
 * goods after the coupon, plus delivery, plus every extra line — but read from
 * the order's own columns rather than from a cart that is long gone. It used to
 * be spelled out a second time in the shipping module (OrderShippingController),
 * and a total computed in two places is a total that eventually disagrees with
 * itself.
 *
 * The order of operations matters and is the whole reason this is a class:
 * subtotal has to be on the order BEFORE the extra lines are asked for, because
 * whoever answers (taxes, today) reads the order to work out what it owes. So
 * items land first, then `checkout.order.recalculated` lets those modules
 * rewrite their own snapshots, and only then is the total summed from what they
 * now say.
 */
class OrderTotals
{
    /**
     * Recompute subtotal from the lines, let the extra-total modules resnapshot,
     * and store the new total. Returns the order, saved.
     *
     * Callers that changed the items pass them in; callers that changed only
     * shipping or the discount pass nothing and keep the lines they have.
     *
     * @param  list<array<string,mixed>>|null  $items
     */
    public function recalculate(Order $order, ?array $items = null): Order
    {
        if ($items !== null) {
            $order->items = array_values($items);
        }

        $order->subtotal = $this->subtotal($order);
        // The discount follows the contents: a coupon's ten percent is ten
        // percent of what the order holds NOW, and so is the manager's own.
        app(OrderDiscounts::class)->apply($order);
        $order->save();

        // "The order's contents changed — anything you froze about it is stale."
        // The taxes module answers by rewriting m_taxes_order_taxes; a module
        // that has nothing frozen ignores it. Deliberately NOT the same hook as
        // checkout.order_placed: that one also sends mail and touches counters.
        hook_action('checkout.order.recalculated', ['order' => $order]);

        $order->total = $this->total($order);
        $order->save();

        return $order;
    }

    /** Goods only, from the stored lines. */
    public function subtotal(Order $order): int
    {
        return array_sum(array_map(
            fn (array $item) => (int) ($item['line_total'] ?? 0),
            $order->items ?? [],
        ));
    }

    /**
     * What the customer pays. `included` lines (VAT already inside the prices)
     * are disclosed, not added — adding them charges the tax twice.
     */
    public function total(Order $order): int
    {
        $extra = array_sum(array_map(
            fn (array $line) => $line['included'] ? 0 : $line['amount'],
            hook_filter('checkout.order.extra_totals', [], ['order' => $order]),
        ));

        return max(0, $order->subtotal - $order->discount_amount) + $order->shipping_cost + $extra;
    }
}
