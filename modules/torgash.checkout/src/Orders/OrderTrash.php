<?php

namespace Torgash\Checkout\Orders;

use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Models\OrderStatus;

/**
 * Taking an order out of the shop's books, and putting it back.
 *
 * What an order in the trash must NOT do is keep goods off the shelf: the
 * reason it is there is that nobody is going to buy them (a test, spam through
 * the one-click form). So trashing puts back what it took, and restoring takes
 * it again — by the same OrderStock arithmetic a status change uses, so a
 * warehouse breakdown is credited to the shelves it came from.
 *
 * An order that has MONEY on it is refused: deleting it would erase the fact
 * of a payment, and "refund it first" is the honest order of events. That is
 * also why the check is here and not in a controller — the list's bulk bar and
 * the order's own page ask the same question.
 *
 * The stock flag is written QUIETLY: moving to the trash is not an edit of the
 * order, and announcing it as `checkout.order.changed` would send a webhook to
 * a CRM about a spam order being tidied away.
 */
class OrderTrash
{
    public function __construct(protected OrderStock $stock) {}

    /** Why this order cannot go to the trash — or null when it can. */
    public function refusal(Order $order): ?string
    {
        return (int) $order->paid_total > 0
            ? __('Money has been received for order :number — refund it or correct the payment first.', ['number' => $order->order_number])
            : null;
    }

    public function trash(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $returned = false;

            if ($order->stock_deducted) {
                $order->items = $this->stock->restoreAll((array) $order->items, $order);
                $order->stock_deducted = false;
                $order->saveQuietly();
                $returned = true;
            }

            $this->log($order, $returned ? 'Moved to the trash; its goods were put back on the shelf' : 'Moved to the trash');
            $order->delete();
        });
    }

    public function restore(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->restore();

            // Back in a status that holds stock: take it again, exactly as
            // moving into that status would have.
            $status = OrderStatus::find($order->status);
            if ($status?->inventory_action === 'decrease' && ! $order->stock_deducted) {
                $order->items = $this->stock->deductAll((array) $order->items, $order);
                $order->stock_deducted = true;
                $order->saveQuietly();
            }

            $this->log($order, 'Restored from the trash');
        });
    }

    /** Gone for good — its log, tax lines and promotion records go with it (FK cascade). */
    public function purge(Order $order): void
    {
        $order->forceDelete();
    }

    protected function log(Order $order, string $description): void
    {
        OrderLog::record($order, $description);
    }
}
