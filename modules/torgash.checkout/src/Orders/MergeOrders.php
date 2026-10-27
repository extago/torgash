<?php

namespace Torgash\Checkout\Orders;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Models\OrderMerge;
use Torgash\Checkout\Models\OrderStatus;

/**
 * Pouring one order into another (PLAN §40.10/4) — the one place it happens,
 * as PlaceOrder is for placing one.
 *
 * The customer ordered twice and wants one parcel. The lines of B join A with
 * everything they were bought with: their discounts stay on them (the coupon's
 * lines, a promotion's shares), their stock stays taken — the goods did not go
 * back to the shelf, they changed orders — and the money paid for B is now
 * money paid for A. A keeps its delivery; B's is dropped, because there is one
 * parcel now (a manager re-quotes A's delivery with the button already there
 * when the weight moved). B stays behind as a shell in the closed `merged`
 * status: its number, its history, a link to A.
 *
 * What other modules keep about an order they move themselves, told through
 * `checkout.order.merging` (context `into`, `from`, `keys` old line key → new,
 * `merge`) INSIDE the transaction, before A is recalculated — promotions move
 * their rows and rename their shares' keys, points and gift cards their
 * ledgers. A module that can say "not this one" answers
 * `checkout.order.merge_refusals` (a list of reasons).
 *
 * Everything is written down on `m_checkout_order_merges` so the merge can be
 * undone (`undo()`) — until anything at all happens to either order after it.
 */
class MergeOrders
{
    /**
     * Set while this class itself is writing a merged order — the one writer
     * a merged order accepts (see `guard()`). Bound as a singleton, so this is
     * the request's own state, reset in `finally`.
     */
    protected bool $touching = false;

    public function __construct(protected OrderTotals $totals, protected OrderStock $stock) {}

    /**
     * A merged order is a closed shell: no edit of its lines, its discounts,
     * its delivery or its money, by any door — the admin, the API, a module.
     * Asked by the Order model before every update; merging and undoing a
     * merge are the only writers it lets through.
     */
    public function guard(Order $order): void
    {
        if (! $this->touching && $order->getOriginal('status') === OrderStatus::MERGED && $order->isDirty()) {
            // Its history, its manager or a note are not the goods or the money.
            if (array_diff(array_keys($order->getDirty()), ['manager_id', 'updated_at', 'admin_note', 'deleted_at']) === []) {
                return;
            }

            throw new RuntimeException(__('Order :number has been merged into another and cannot be changed — undo the merge instead.', ['number' => $order->order_number]));
        }
    }

    /** @template T @param  callable(): T  $work @return T */
    protected function touching(callable $work): mixed
    {
        $this->touching = true;

        try {
            return $work();
        } finally {
            $this->touching = false;
        }
    }

    /** @return list<string> why these two cannot be merged; empty = they can */
    public function refusals(Order $into, Order $from): array
    {
        $why = [];

        if ($into->is($from)) {
            return [__('An order cannot be merged into itself.')];
        }

        if (! OrderStatus::find(OrderStatus::MERGED)) {
            return [__('The “Merged” status has been deleted, so orders cannot be merged.')];
        }

        foreach ([$into, $from] as $order) {
            if ($order->merged_into_id || $order->status === OrderStatus::MERGED) {
                $why[] = __('Order :number has already been merged into another.', ['number' => $order->order_number]);
            } elseif (in_array($order->status, $this->cancelled(), true)) {
                $why[] = __('Order :number is cancelled.', ['number' => $order->order_number]);
            }
        }

        // One level only. Pouring C into A that already holds B is ordinary;
        // pouring A (holding B) into C would make B's way back a chain nobody
        // can follow on a screen — the same parcel is had by pouring C into A.
        if (Order::where('merged_into_id', $from->id)->exists()) {
            $why[] = __('Other orders have been merged into :number — merge the other way round, into :number.', ['number' => $from->order_number]);
        }

        // Two accounts' orders are two customers' goods; a mistake here puts
        // one person's parcel in another's name. Guests are not compared —
        // a guest's order has no account to disagree with.
        if ($into->user_id && $from->user_id && $into->user_id !== $from->user_id) {
            $why[] = __('The orders belong to different customers.');
        }

        if ($into->currency_code !== $from->currency_code || $into->chargeCurrency() !== $from->chargeCurrency()) {
            $why[] = __('The orders are in different currencies.');
        } elseif ($into->paymentCurrency() !== $from->paymentCurrency()) {
            $why[] = __('The orders are paid in different currencies.');
        }

        return [...$why, ...hook_filter('checkout.order.merge_refusals', [], ['into' => $into, 'from' => $from])];
    }

    /**
     * Pour `$from` into `$into`. Throws with the reasons when it may not.
     */
    public function merge(Order $into, Order $from, ?User $by = null): OrderMerge
    {
        if ($why = $this->refusals($into, $from)) {
            throw new RuntimeException(implode(' ', $why));
        }

        $cause = ['type' => 'merge', 'order_id' => $into->id, 'order_number' => $into->order_number];

        return $this->touching(fn () => app(\Torgash\Catalog\Stock\StockMoves::class)->because($cause, fn () => DB::transaction(function () use ($into, $from, $by) {
            $into = Order::lockForUpdate()->findOrFail($into->id);
            $from = Order::lockForUpdate()->findOrFail($from->id);

            $keys = $this->keys($into, $from);

            $merge = OrderMerge::create([
                'into_id' => $into->id,
                'from_id' => $from->id,
                'keys' => $keys,
                'into_before' => $this->columns($into),
                'from_before' => $this->columns($from),
                'user_id' => $by?->id,
                'user_name' => $by?->name,
            ]);

            // The goods: B's lines under their new keys, marked with where they
            // came from. Taken from the shelf exactly once — if only one of the
            // two orders held stock, B's lines are brought to A's side.
            $moved = array_map(function (array $item) use ($keys, $from) {
                $key = OrderDiscounts::keyOf($item);
                $item['key'] = $keys[$key] ?? $key;
                if (($item['parent_key'] ?? null) !== null) {
                    $item['parent_key'] = $keys[(string) $item['parent_key']] ?? $item['parent_key'];
                }
                $item['merged_from'] = $from->order_number;

                return $item;
            }, (array) $from->items);

            if ($into->stock_deducted && ! $from->stock_deducted) {
                $moved = $this->stock->deductAll($moved, $into);
            } elseif (! $into->stock_deducted && $from->stock_deducted) {
                $moved = $this->stock->restoreAll($moved, $from);
            }

            $into->items = [...(array) $into->items, ...$moved];

            // The money: what was paid for B was paid for A. What B's discounts
            // were agreed on travels with its lines (discounts()).
            $into->paid_total = (int) $into->paid_total + (int) $from->paid_total;
            $into->prepaid_total = (int) $into->prepaid_total + (int) $from->prepaid_total;
            $into->paid_at = $into->paid_at ?? $from->paid_at;
            if ($into->deposit_amount || $from->deposit_amount) {
                $into->deposit_amount = (int) $into->deposit_amount + (int) $from->deposit_amount;
            }
            $this->discounts($into, $from, $keys);
            $into->save();

            $from->update([
                'items' => [],
                'subtotal' => 0, 'discount_amount' => 0, 'shipping_cost' => 0, 'total' => 0,
                'paid_total' => 0, 'prepaid_total' => 0, 'deposit_amount' => null,
                'coupon_code' => null, 'coupon_terms' => null, 'manual_discount' => null, 'other_discounts' => null,
                'stock_deducted' => false,
                'status' => OrderStatus::MERGED,
                'merged_into_id' => $into->id,
            ]);

            hook_action('checkout.order.merging', ['into' => $into, 'from' => $from, 'keys' => $keys, 'merge' => $merge]);

            $this->totals->recalculate($into);
            // B too: what other modules froze about it (a tax snapshot) is of
            // lines it no longer holds.
            $this->totals->recalculate($from);
            $this->settle($into);

            $merge->update([
                'into_log_id' => OrderLog::record($into, 'Order :number merged into this one', ['number' => $from->order_number])->id,
                'from_log_id' => OrderLog::record($from, 'Merged into order :number', ['number' => $into->order_number])->id,
            ]);

            hook_action('checkout.order.merged', ['into' => $into, 'from' => $from, 'keys' => $keys, 'merge' => $merge]);

            return $merge;
        })));
    }

    /** @return list<string> why this merge can no longer be undone; empty = it can */
    public function undoRefusals(OrderMerge $merge): array
    {
        if ($merge->undone_at) {
            return [__('This merge has already been undone.')];
        }

        $into = Order::withTrashed()->find($merge->into_id);
        $from = Order::withTrashed()->find($merge->from_id);

        if (! $into || ! $from || $into->trashed() || $from->trashed()) {
            return [__('One of the orders is gone.')];
        }

        if (OrderMerge::where('into_id', $into->id)->whereNull('undone_at')->where('id', '>', $merge->id)->exists()) {
            return [__('Another order has been merged into :number since — undo that first.', ['number' => $into->order_number])];
        }

        // The point of no return is ANY event after the merge — a payment, a
        // refund, a status, an edit, anything the history records — not a list
        // of fields that count, which would be the list somebody forgets to
        // extend.
        $after = OrderLog::where(fn ($q) => $q
            ->where(fn ($q) => $q->where('order_id', $into->id)->where('id', '>', (int) $merge->into_log_id))
            ->orWhere(fn ($q) => $q->where('order_id', $from->id)->where('id', '>', (int) $merge->from_log_id)))
            ->exists();

        $why = $after ? [__('Something has happened to these orders since they were merged.')] : [];

        return [...$why, ...hook_filter('checkout.order.unmerge_refusals', [], ['merge' => $merge, 'into' => $into, 'from' => $from])];
    }

    /** Put both orders back as they were. Throws with the reasons when it may not. */
    public function undo(OrderMerge $merge, ?User $by = null): void
    {
        if ($why = $this->undoRefusals($merge)) {
            throw new RuntimeException(implode(' ', $why));
        }

        $cause = ['type' => 'merge', 'order_id' => $merge->into_id];

        $this->touching(fn () => app(\Torgash\Catalog\Stock\StockMoves::class)->because($cause, fn () => DB::transaction(function () use ($merge) {
            $into = Order::lockForUpdate()->findOrFail($merge->into_id);
            $from = Order::lockForUpdate()->findOrFail($merge->from_id);

            // Nothing happened since, so "as they were" is exact — with one
            // exception: stock, when the merge brought B's lines to A's side.
            $before = (array) $merge->from_before;
            $wasDeducted = (bool) ($before['stock_deducted'] ?? false);
            if ((bool) $into->stock_deducted !== $wasDeducted) {
                $lines = array_values(array_filter((array) $into->items, fn (array $item) => ($item['merged_from'] ?? null) === $from->order_number));
                $wasDeducted ? $this->stock->deductAll($lines, $from) : $this->stock->restoreAll($lines, $from);
            }

            hook_action('checkout.order.unmerging', ['into' => $into, 'from' => $from, 'keys' => (array) $merge->keys, 'merge' => $merge]);

            $into->forceFill((array) $merge->into_before)->save();
            $from->forceFill($before)->save();

            // What other modules froze (a tax snapshot) follows the contents
            // back; the sums come out as they were, since nothing happened.
            $this->totals->recalculate($into);
            $this->totals->recalculate($from);

            $merge->update(['undone_at' => now()]);

            OrderLog::record($into, 'Merge with order :number undone', ['number' => $from->order_number]);
            OrderLog::record($from, 'Merge into order :number undone', ['number' => $into->order_number]);

            hook_action('checkout.order.unmerged', ['into' => $into, 'from' => $from, 'keys' => (array) $merge->keys, 'merge' => $merge]);
        })));
    }

    /**
     * B's line keys as they will be in A. A key A already uses is renamed —
     * the admin URL, a promotion's share and the warehouse breakdown all name
     * a line by it, so two lines may never share one.
     *
     * @return array<string, string>
     */
    protected function keys(Order $into, Order $from): array
    {
        $taken = array_flip(array_map(fn (array $item) => OrderDiscounts::keyOf($item), (array) $into->items));
        $keys = [];

        foreach ((array) $from->items as $item) {
            $key = OrderDiscounts::keyOf($item);
            $new = $key;
            for ($n = 1; isset($taken[$new]); $n++) {
                $new = $key.'-'.$from->order_number.($n > 1 ? '-'.$n : '');
            }
            $taken[$new] = true;
            $keys[$key] = $new;
        }

        return $keys;
    }

    /**
     * B's discounts, fixed to B's lines in A.
     *
     * The same coupon on both: one coupon covering both sets of lines. B's
     * coupon where A has none: it becomes A's. A different coupon on each (A
     * can hold one): B's travels as TERMS on its own lines, printed "Coupon X
     * from order B", recomputed by OrderDiscounts like a coupon is. B's own
     * manager discount travels the same way; what other modules took off
     * (points) was money and stays money.
     *
     * @param  array<string, string>  $keys
     */
    protected function discounts(Order $into, Order $from, array $keys): void
    {
        $others = (array) $into->other_discounts;
        $terms = $from->coupon_terms;

        if (is_array($terms)) {
            $terms['lines'] = $this->renamed($terms['lines'] ?? OrderDiscounts::quantities((array) $from->items), $keys);

            if ($into->coupon_code === null && ! is_array($into->coupon_terms)) {
                $into->coupon_code = $from->coupon_code;
                $into->coupon_terms = $terms;
            } elseif ($into->coupon_code === $from->coupon_code && is_array($into->coupon_terms) && is_array($into->coupon_terms['lines'] ?? null)) {
                $mine = $into->coupon_terms;
                $mine['lines'] = $mine['lines'] + $terms['lines'];
                $mine['excluded'] = (int) ($mine['excluded'] ?? 0) + (int) ($terms['excluded'] ?? 0);
                $into->coupon_terms = $mine;
            } else {
                $others[] = [
                    'code' => 'coupon:'.$from->coupon_code,
                    'label' => __('Coupon :code from order :number', ['code' => $from->coupon_code, 'number' => $from->order_number]),
                    'terms' => $terms,
                    'amount' => $from->couponDiscount(),
                ];
            }
        }

        if (is_array($from->manual_discount)) {
            $others[] = [
                'code' => 'manual:'.$from->order_number,
                'label' => __('Discount from order :number', ['number' => $from->order_number]),
                'terms' => [
                    'type' => $from->manual_discount['type'] ?? 'fixed',
                    'value' => (int) ($from->manual_discount['value'] ?? 0),
                    'min_subtotal' => null,
                    'excluded' => 0,
                    'lines' => $this->renamed(OrderDiscounts::quantities((array) $from->items), $keys),
                ],
                'reason' => $from->manual_discount['reason'] ?? null,
                'amount' => $from->manualDiscount(),
            ];
        }

        foreach ((array) $from->other_discounts as $other) {
            if (is_array($other['terms']['lines'] ?? null)) {
                $other['terms']['lines'] = $this->renamed($other['terms']['lines'], $keys);
            }
            $others[] = $other;
        }

        $into->other_discounts = $others ?: null;
    }

    /**
     * @param  array<string, int>  $lines
     * @param  array<string, string>  $keys
     * @return array<string, int>
     */
    protected function renamed(array $lines, array $keys): array
    {
        $out = [];
        foreach ($lines as $key => $qty) {
            $out[$keys[(string) $key] ?? (string) $key] = (int) $qty;
        }

        return $out;
    }

    /**
     * A's payment state from what it now holds. A payments module answers
     * `checkout.order.merged` with the state its payments say; this is the
     * answer for money nobody's gateway holds.
     */
    protected function settle(Order $into): void
    {
        $paid = (int) $into->paid_total;
        $state = match (true) {
            $paid <= 0 => $into->payment_state,
            $into->payment_state === PaymentState::AUTHORIZED => PaymentState::AUTHORIZED,
            $paid >= (int) $into->total => PaymentState::PAID,
            default => PaymentState::PARTIALLY_PAID,
        };

        if ($state !== $into->payment_state) {
            $into->update(['payment_state' => $state]);
        }
    }

    /** @return array<string, mixed> an order's own columns, as stored */
    protected function columns(Order $order): array
    {
        return collect($order->getAttributes())
            ->except(['id', 'created_at', 'updated_at', 'deleted_at', 'order_number'])
            ->map(fn ($value, $column) => $order->hasCast($column, ['array', 'json']) ? $order->{$column} : $value)
            ->all();
    }

    /** @return list<string> */
    protected function cancelled(): array
    {
        return ['cancelled', 'declined', 'failed'];
    }
}
