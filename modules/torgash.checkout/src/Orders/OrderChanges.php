<?php

namespace Torgash\Checkout\Orders;

use Illuminate\Support\Facades\Log;
use Torgash\Checkout\Models\Order;

/**
 * The one place that answers "what changed on this order, and what was it
 * before" — the funnel behind the `checkout.order.changed` hook.
 *
 * Why a collector and not a hook call in each controller: an order is edited
 * from five screens today (status, manager, payment method, contents, and
 * delivery, which lives in ANOTHER module), and the count only grows. Every one
 * of those wrote a line to OrderLog and announced nothing, because announcing
 * was a step somebody had to remember. Listening to the model instead means a
 * module that edits an order cannot forget, and does not need to know we exist.
 *
 * Three decisions are baked in, and each has a reason that bites otherwise:
 *
 * 1. **Diffs are coalesced per order, not per save().** One admin action can be
 *    several writes — OrderTotals alone saves twice on purpose (items and
 *    subtotal first, so the extra-total modules can re-snapshot, then the
 *    total). A consumer wants ONE event saying "items, subtotal and total moved,
 *    here is from and to", not three, and certainly not an intermediate state
 *    where the lines are new and the total is still the old one.
 *
 * 2. **It fires after the response, not mid-edit.** Everything that recomputes a
 *    placed order runs inside DB::transaction, and a webhook or a CRM push sent
 *    from inside a transaction that then rolls back is a lie no consumer can
 *    take back. Flushing on terminate also keeps a slow listener off the
 *    admin's click.
 *
 * 3. **A just-placed order has no "change".** `wasRecentlyCreated` is true only
 *    on the instance that did the INSERT, which is exactly the checkout's own
 *    `$order->update(['order_number' => ...])` — placement is announced by
 *    `checkout.order_placed` and must not arrive a second time as a diff.
 *
 * The existing `checkout.order_status_changed` stays where it is: it fires
 * immediately, carries the status model, and drives the customer email. This
 * hook is the general one — "something about this order is not what it was" —
 * and the two are meant to overlap on a status change.
 */
class OrderChanges
{
    /** @var array<int, array{order: Order, before: array<string, mixed>, after: array<string, mixed>}> */
    protected array $pending = [];

    /**
     * Bookkeeping columns say when a row was touched, not what about it is
     * different; a diff carrying them would make every listener filter them out.
     */
    protected const IGNORED = ['created_at', 'updated_at'];

    /**
     * Fold one save() into the order's pending diff.
     *
     * `before` is written once and never overwritten — on the second save of the
     * same edit the model's "original" is already the intermediate value, and
     * keeping the first one is what makes the diff span the whole action.
     */
    public function record(Order $order): void
    {
        if ($order->wasRecentlyCreated) {
            return;
        }

        $changed = array_diff_key($order->getChanges(), array_flip(self::IGNORED));

        if ($changed === []) {
            return;
        }

        $entry = $this->pending[$order->id] ?? ['order' => $order, 'before' => [], 'after' => []];
        $entry['order'] = $order;

        foreach (array_keys($changed) as $key) {
            if (! array_key_exists($key, $entry['before'])) {
                $entry['before'][$key] = $order->getOriginal($key);
            }

            $entry['after'][$key] = $order->getAttribute($key);
        }

        $this->pending[$order->id] = $entry;
    }

    /**
     * Announce every order edited since the last flush, then forget them.
     *
     * A field written back to the value it already had is dropped here rather
     * than at record() time: only once the whole action is folded together can
     * "set to X, then back to Y" be told from a real change, and an order whose
     * every field landed where it started is not an event at all.
     */
    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $entry) {
            $before = $entry['before'];
            $after = $entry['after'];

            foreach ($before as $key => $was) {
                if ($was === $after[$key]) {
                    unset($before[$key], $after[$key]);
                }
            }

            if ($before === []) {
                continue;
            }

            // Same bargain as the order emails: a listener that blows up must
            // not take down the request that edited the order — the edit is
            // committed and correct, and a missed announcement is the smaller
            // failure. It goes to the log rather than being swallowed.
            try {
                hook_action('checkout.order.changed', [
                    'order' => $entry['order'],
                    'before' => $before,
                    'after' => $after,
                    'changed' => array_keys($after),
                ]);
            } catch (\Throwable $e) {
                Log::error('A checkout.order.changed listener failed', [
                    'order_id' => $entry['order']->id,
                    'changed' => array_keys($after),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
