<?php

namespace Torgash\Payments\Api;

use Illuminate\Support\Collection;
use Torgash\Checkout\Models\Order;
use Torgash\Payments\Ledger;
use Torgash\Payments\Models\Payment;

/**
 * An order's payments in the API's answer (`payment.payments`), for a page of
 * fifty orders in ONE query rather than fifty (PLAN §40.10/4).
 *
 * The API announces the page it is about to serialize (`api.resource.listed`);
 * this reads every payment of those orders and of the orders poured into them
 * at once, and each order's answer is then read from memory. An order the
 * page never announced (`GET /orders/{id}`) is asked about on its own — one
 * query for one order is not the problem. A per-request singleton, forgotten
 * when the request is rebound, never a static.
 */
class OrderPayments
{
    /** @var array<int, Collection<int, Payment>> order id => its payments, merged orders' included */
    protected array $byOrder = [];

    /** @param  Collection<int, mixed>  $items */
    public function prime(Collection $items): void
    {
        $orders = $items->filter(fn ($item) => $item instanceof Order)->keyBy('id');

        if ($orders->isEmpty()) {
            return;
        }

        $ids = $orders->keys()->all();
        $payments = Payment::query()
            ->select('m_payments_payments.*')
            ->selectRaw('coalesce(o.merged_into_id, m_payments_payments.order_id) as belongs_to')
            ->join('m_checkout_orders as o', 'o.id', '=', 'm_payments_payments.order_id')
            ->where(fn ($q) => $q->whereIn('m_payments_payments.order_id', $ids)->orWhereIn('o.merged_into_id', $ids))
            ->orderBy('m_payments_payments.id')
            ->get()
            ->groupBy('belongs_to');

        foreach ($ids as $id) {
            $this->byOrder[$id] = $payments->get($id, collect())->values();
        }
    }

    /** @return list<array<string, mixed>> */
    public function for(Order $order): array
    {
        $payments = $this->byOrder[$order->id] ?? app(Ledger::class)->of($order);

        return $payments->map(fn (Payment $payment) => [
            'id' => $payment->id,
            'gateway' => $payment->gateway,
            'external_id' => $payment->external_id,
            'state' => $payment->state,
            // Minor units of the order's `currency`, like every amount on it.
            'requested' => $payment->requested,
            'held' => $payment->held(),
            // Set when the payment was made for an order since poured into this one.
            'order_id' => $payment->order_id !== $order->id ? $payment->order_id : null,
            'created_at' => $payment->created_at?->toIso8601String(),
        ])->values()->all();
    }
}
