<?php

namespace Torgash\Rma\Invariants;

use Torgash\Checkout\Models\Order;
use Torgash\Rma\Models\ReturnRequest;
use Torgash\Rma\State;

/**
 * What must be true about returns, said by the module that takes them.
 *
 * Answered lazily through `guard.invariants`; nothing here depends on the guard
 * being installed.
 *
 * The statement is about the one number a return may never exceed: what the
 * order actually holds. Every door this module opens bounds it — `lines()` caps
 * each quantity by `Eligibility::remaining()` — which is exactly why the
 * statement is worth making, and not the reverse. A bound enforced in code is
 * enforced by the code that remembers to call it, and the next door (an API, an
 * import, a hand-written fix in the database, a second return approved while
 * the first was open in another tab) is the one that will not. What the shop
 * gets then is goods put back on the shelf that were never sold and a refund
 * for more than was paid — both of which look like ordinary rows.
 *
 * Bounded by its own scan and says so: the most recent returns, not every
 * return the shop has ever taken, because this reads two jsonb columns in PHP.
 */
class RmaInvariants
{
    /** How many recent return requests are read at most, whatever the engine's own limit is. */
    protected const SCAN = 500;

    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            'rma.returns_within_the_order' => [
                'label' => 'No order has more sent back than was bought',
                'explain' => 'The returns against this order claim more of a product than the order contains. Every screen bounds a return by what is left, so this came in another way — an API call, an import, or two returns approved at the same moment. The goods go back on the shelf and the money goes back to the customer on the strength of these numbers, so open the order\'s returns and correct the quantities before either happens again.',
                'check' => function (int $limit) {
                    // Newest first: a drift that matters is one nobody has
                    // acted on yet, and the oldest returns in a shop are the
                    // ones already refunded and shelved.
                    $requests = ReturnRequest::query()
                        ->where('state', '!=', State::DECLINED)
                        ->latest('id')
                        ->limit(self::SCAN)
                        ->get(['id', 'order_id', 'enquiry_id', 'lines']);

                    $claimed = [];

                    foreach ($requests as $request) {
                        foreach ((array) $request->lines as $line) {
                            $orderId = (int) $request->order_id;
                            $productId = (int) ($line['product_id'] ?? 0);
                            $claimed[$orderId][$productId] = ($claimed[$orderId][$productId] ?? 0) + (int) ($line['qty'] ?? 0);
                        }
                    }

                    $found = [];

                    foreach (Order::whereIn('id', array_keys($claimed))->get(['id', 'order_number', 'items']) as $order) {
                        if (count($found) >= $limit) {
                            break;
                        }

                        $bought = [];

                        foreach ((array) $order->items as $line) {
                            $productId = (int) ($line['product_id'] ?? 0);
                            $bought[$productId] = ($bought[$productId] ?? 0) + (int) ($line['qty'] ?? 0);
                        }

                        // Summed per PRODUCT rather than per line, deliberately:
                        // one product can sit on two lines of an order (a
                        // processor inside a server and the same processor on
                        // its own), and Eligibility::remaining() consumes a
                        // claim across those lines. The question here is the
                        // total, which is the one number that cannot be argued
                        // with.
                        foreach ($claimed[$order->id] as $productId => $qty) {
                            if ($qty <= ($bought[$productId] ?? 0)) {
                                continue;
                            }

                            $found[] = [
                                'id' => (string) $order->id,
                                'title' => $order->order_number ?: '#'.$order->id,
                                'detail' => [
                                    'Product :product: :claimed sent back against :bought bought.',
                                    ['product' => $productId, 'claimed' => $qty, 'bought' => $bought[$productId] ?? 0],
                                ],
                                'url' => route('admin.orders.show', $order->order_number ?: $order->id),
                            ];

                            // One finding per ORDER: the shop opens the order's
                            // returns and sees every line at once, and a row
                            // per product would make one mistake look like
                            // five.
                            break;
                        }
                    }

                    return $found;
                },
            ],
        ];
    }
}
