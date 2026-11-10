<?php

namespace Torgash\Payments;

use Illuminate\Support\Str;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Payments\Models\Payment;
use Torgash\Payments\Models\Transaction;

/**
 * What unsigned callbacks left behind while they still could.
 *
 * Up to 0.5.9 a callback whose signature failed was logged with the order, the
 * invoice id, the state and the amount it claimed, and such a row began a
 * payment. A forged "paid" was then a payment holding money, counted as soon as
 * anything recalculated that order. Stopping new ones leaves those in place, and
 * they would go on being counted.
 *
 * A payment is taken for forged on two counts together: the row that MADE it is
 * unverified (the same row, written the same moment, so a later unsigned message
 * naming a real invoice condemns nothing), and nothing verified ever spoke for
 * it. The second alone would not do: the log is swept after months, and an old
 * real payment has no rows left at all.
 *
 * The order's payment columns are worked out again; its status and stock are
 * left alone. Whether goods have already gone to somebody is a person's
 * question, and the order's history says why the money changed.
 */
class ForgedPayments
{
    public function __construct(protected Ledger $ledger) {}

    /** @return list<string> numbers of the orders that had one */
    public function clear(): array
    {
        $table = (new Payment)->getTable();
        $log = (new Transaction)->getTable();

        $forged = Payment::query()
            ->whereNotNull('external_id')
            ->whereExists(fn ($made) => $made->selectRaw('1')->from($log.' as made')
                ->where('made.verified', false)
                ->whereColumn('made.payment_id', $table.'.id')
                ->whereColumn('made.created_at', $table.'.created_at'))
            ->whereNotExists(fn ($vouched) => $vouched->selectRaw('1')->from($log.' as vouched')
                ->where('vouched.verified', true)
                ->where(fn ($same) => $same->whereColumn('vouched.payment_id', $table.'.id')
                    ->orWhere(fn ($named) => $named->whereColumn('vouched.gateway', $table.'.gateway')
                        ->whereColumn('vouched.external_id', $table.'.external_id'))))
            ->get();

        $orders = [];

        foreach ($forged as $payment) {
            $payment->delete();

            if ($order = Order::withTrashed()->find($payment->order_id)) {
                OrderLog::record($order, 'A payment no gateway confirmed was removed (:gateway, :id)', [
                    'gateway' => $payment->gateway,
                    'id' => Str::limit((string) $payment->external_id, 64),
                ], by: null);

                $orders[$order->id] = $order;
            }
        }

        foreach ($orders as $order) {
            $this->ledger->resettle($order->mergeRoot());
        }

        // The rows stay, as the text that arrived. What could be believed goes.
        Transaction::where('verified', false)->update([
            'order_id' => null,
            'payment_id' => null,
            'external_id' => null,
            'event_key' => null,
            'state' => null,
            'amount' => null,
        ]);

        return array_values(array_map(fn (Order $order) => (string) $order->order_number, $orders));
    }
}
