<?php

namespace Torgash\CommerceMl\Sale;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\CommerceMl\Models\OrderSync;

/**
 * Which orders 1C is owed, and which it already has.
 *
 * "Every order" is wrong in both directions: a shop that runs its warehouse in
 * 1C wants the goods written off when an order is real, and what makes an order
 * real differs per shop — paid, for a prepaying shop; confirmed by a manager,
 * for one whose CRM does the qualifying; placed at all, for one that just wants
 * the documents. None of those is a better default than the others, so the
 * condition is the shop's to state, on the two axes an order already has:
 * status (its own editable workflow) and payment (the fixed vocabulary).
 *
 * The second half of the question is "again?". An order is not a one-shot: it
 * changes after it is placed, and 1C is entitled to the current version. The
 * marker is a hash of the exported document, so any change to what would be
 * sent sends it, and nobody has to keep a list of the fields that count.
 */
class Outbox
{
    public function __construct(protected OrderDocument $document) {}

    /** Is the sale half switched on at all? */
    public function enabled(): bool
    {
        return (bool) setting('torgash.commerceml', 'sale_enabled', true);
    }

    /**
     * The next orders to hand over, with the document each would produce.
     *
     * Returns pairs rather than orders because the hash is a by-product of
     * rendering: computing it separately would render every order twice.
     *
     * @return Collection<int, array{order: Order, xml: string, hash: string}>
     */
    public function due(int $limit): Collection
    {
        if (! $this->enabled()) {
            return collect();
        }

        $orders = $this->candidates()->limit($limit)->get();

        if ($orders->isEmpty()) {
            return collect();
        }

        $this->document->prime($orders);
        $syncs = OrderSync::whereIn('order_id', $orders->modelKeys())->get()->keyBy('order_id');
        $unchanged = [];

        $rows = $orders->map(function (Order $order) {
            $xml = $this->document->render($order);

            return ['order' => $order, 'xml' => $xml, 'hash' => sha1($xml)];
        })->reject(function (array $row) use ($syncs, &$unchanged) {
            if (! ($syncs->get($row['order']->id)?->isCurrent($row['hash']) ?? false)) {
                return false;
            }

            // Identical to what 1C confirmed, even though the order was touched
            // since — somebody opened and saved it, or a module rewrote a field
            // 1C is not told about. Move the clock so the same order is not
            // rendered again on every exchange from now on.
            $unchanged[] = $row['order']->id;

            return true;
        })->values();

        if ($unchanged !== []) {
            OrderSync::whereIn('order_id', $unchanged)->update(['confirmed_at' => now(), 'updated_at' => now()]);
        }

        return $rows;
    }

    /**
     * Remember what was handed over. Not confirmed yet: 1C says `success` when
     * it has actually taken the file, and an exchange that dies before that
     * must offer the same orders again rather than lose them.
     *
     * @param  Collection<int, array{order: Order, xml: string, hash: string}>  $rows
     */
    public function offered(Collection $rows): void
    {
        foreach ($rows as $row) {
            OrderSync::updateOrCreate(
                ['order_id' => $row['order']->id],
                ['offered_hash' => $row['hash'], 'offered_at' => now()],
            );
        }
    }

    /**
     * 1C took them: everything currently offered is now what it holds.
     *
     * Not scoped to one exchange, because there is one 1C and one shop — and if
     * an earlier conversation offered an order that this one confirms, that
     * order genuinely has been handed over.
     */
    public function confirm(): int
    {
        return OrderSync::whereNotNull('offered_hash')
            ->where(function (Builder $query) {
                $query->whereNull('confirmed_hash')->orWhereColumn('confirmed_hash', '!=', 'offered_hash');
            })
            ->update([
                'confirmed_hash' => DB::raw('offered_hash'),
                'confirmed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Orders that qualify and may have changed since 1C last confirmed one.
     *
     * The `updated_at` comparison is a cheap pre-filter, not the decision: it
     * removes the untouched orders in SQL, and the hash settles the rest. Doing
     * it the other way round would mean rendering every order the shop has ever
     * placed on every exchange.
     */
    protected function candidates(): Builder
    {
        $statuses = array_filter((array) setting('torgash.commerceml', 'sale_statuses', []));
        $paid = (bool) setting('torgash.commerceml', 'sale_paid', true);
        $all = setting('torgash.commerceml', 'sale_match', 'any') === 'all';

        $query = Order::query()->orderBy('id');

        if ($from = setting('torgash.commerceml', 'sale_from')) {
            // What a shop connecting 1C to a two-year-old catalogue needs first:
            // not its whole order history in one night.
            $query->where('created_at', '>=', $from);
        }

        // Neither axis stated is not "send everything" — it is a shop that has
        // not answered the question yet, and inventing an answer would write off
        // stock in 1C for orders nobody has confirmed.
        if ($statuses === [] && ! $paid) {
            return $query->whereRaw('1 = 0');
        }

        $query->where(function (Builder $where) use ($statuses, $paid, $all) {
            $clause = $all ? 'where' : 'orWhere';

            if ($statuses !== []) {
                $where->{$all ? 'whereIn' : 'orWhereIn'}('status', $statuses);
            }

            if ($paid) {
                $where->{$clause}('payment_state', PaymentState::PAID);
            }
        });

        // Strictly later, not "at or later": timestamps here are whole seconds,
        // and an order edited in the same second as the confirmation would
        // otherwise never be sent again. The cost of the strict comparison is
        // rendering such an order once more, after which the hash matches and
        // its clock is moved forward.
        return $query->whereNotExists(function ($sub) {
            $sub->from('m_commerceml_order_sync')
                ->whereColumn('m_commerceml_order_sync.order_id', 'm_checkout_orders.id')
                ->whereColumn('m_commerceml_order_sync.confirmed_at', '>', 'm_checkout_orders.updated_at');
        });
    }
}
