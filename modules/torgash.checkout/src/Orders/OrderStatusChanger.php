<?php

namespace Torgash\Checkout\Orders;

use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;

/**
 * The one place an order's status moves.
 *
 * Moving between "decrease" and "increase" statuses adjusts stock exactly once
 * (`Order::stock_deducted` tracks the current state), same semantics as
 * the usual per-status inventory flag. That rule, the transaction around it and
 * the announcement afterwards were spelled out inside the admin controller —
 * fine while a human clicking a select was the only way it could happen. It no
 * longer is: an automation rule sets statuses too, and a second implementation
 * of "and also take the stock" is how a shop ends up with inventory that
 * depends on which screen the change came from.
 */
class OrderStatusChanger
{
    public function __construct(protected OrderStock $stock) {}

    /**
     * Returns the order, saved. Announcing is part of the job, not the caller's
     * to remember — but only when the status actually moved, so setting a status
     * to the one it already has stays a no-op rather than an email.
     */
    public function change(Order $order, OrderStatus $to): Order
    {
        $from = OrderStatus::find($order->status);

        // Closed both ways (PLAN §40.10/4): a merged order is a shell whose
        // goods and money are another order's, and only merging puts one
        // there. Every door — the admin, the API, a rule — comes through here.
        if ($order->status === OrderStatus::MERGED || $to->code === OrderStatus::MERGED) {
            if ($order->status !== $to->code) {
                throw new \RuntimeException(__('A merged order’s status cannot be changed — undo the merge instead.'));
            }

            return $order;
        }

        DB::transaction(function () use ($order, $to) {
            $items = $order->items;
            $deducted = (bool) $order->stock_deducted;

            // Three actions, not two. "decrease" commits the units and
            // "increase" puts them back, each at most once — `stock_deducted`
            // is which side of that line the order is on. "none" is the third
            // and is genuinely neutral: an order moved into such a status keeps
            // whatever stock state it already had (the status form calls it
            // "do not touch stock"). It used to be a synonym for "increase",
            // which is the gap this closes.
            if ($to->inventory_action === 'decrease' && ! $deducted) {
                $items = $this->stock->deductAll($items, $order);
                $deducted = true;
            } elseif ($to->inventory_action === 'increase' && $deducted) {
                $items = $this->stock->restoreAll($items, $order);
                $deducted = false;
            }

            $order->update(['status' => $to->code, 'stock_deducted' => $deducted, 'items' => $items]);
        });

        if ($from?->code !== $to->code) {
            hook_action('checkout.order_status_changed', [
                'order' => $order,
                'status' => $to,
                'from' => $from?->t('name'),
                // The row itself, for a listener that has to say it again in
                // somebody else's language later (the order's history).
                'from_status' => $from,
            ]);
        }

        return $order;
    }
}
