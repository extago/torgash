<?php

namespace Torgash\Checkout\Orders;

use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Models\OrderStatus;

/**
 * Abandoned "Awaiting payment" orders, swept on a schedule.
 *
 * An order paid online is placed in `awaiting_payment` and holds no stock until
 * the money arrives (see the add_awaiting_payment_checkout_status migration and
 * UnpaidStockTest). Most are paid within minutes; the rest are a customer who
 * opened the bank's page and closed the tab. Those never resolve on their own —
 * `ReconcilePayments` only chases orders that got as far as a gateway invoice —
 * so without this they sit in the orders list for ever.
 *
 * Deliberately NOT a reservation timeout: there is nothing to release. This only
 * moves a status, through the same OrderStatusChanger the admin's own select
 * calls, so the log line, the customer email and any automation off
 * `checkout.order_status_changed` all happen exactly as they would by hand.
 *
 * Subtractable like the rest of the feature: `cancel_unpaid_after_hours = 0`, a
 * deleted target status, or a deleted `awaiting_payment` row, and it does
 * nothing.
 */
class CancelStaleOrders
{
    /** One sweep's cap; the next run continues from where this one stopped. */
    public const BATCH = 500;

    public function __construct(protected OrderStatusChanger $changer) {}

    /** @return int how many orders were cancelled this run */
    public function run(): int
    {
        $hours = (int) setting('torgash.checkout', 'cancel_unpaid_after_hours', 0);

        if ($hours < 1) {
            return 0;
        }

        $target = OrderStatus::find((string) setting('torgash.checkout', 'cancel_unpaid_status', 'cancelled'));

        // The shop chose a status and then deleted it: do nothing rather than
        // guess a different one. Moving an order to the status it is already in
        // would be a no-op anyway, but the guard says the intent out loud.
        if (! $target || $target->code === OrderStatus::AWAITING_PAYMENT) {
            return 0;
        }

        $orders = Order::query()
            ->where('status', OrderStatus::AWAITING_PAYMENT)
            // Never an order the money reached — a two-phase hold counts as
            // reached (see PaymentState::withMoney), because a shop that
            // captures holds by hand must keep the time to do it.
            // Nothing from a BANK: a gift card that paid part of it is not the
            // customer having paid, and it goes back onto the card when the
            // order is cancelled (torgash.giftcards).
            ->whereNotIn('payment_state', [PaymentState::AUTHORIZED, PaymentState::PAID, PaymentState::PARTIALLY_REFUNDED])
            ->whereColumn('paid_total', '<=', 'prepaid_total')
            ->where('created_at', '<', now()->subHours($hours))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($orders as $order) {
            $this->changer->change($order, $target);

            OrderLog::record($order, 'Auto-cancelled: unpaid for :hours h', ['hours' => $hours], by: null);
        }

        return $orders->count();
    }
}
