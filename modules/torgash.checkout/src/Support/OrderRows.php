<?php

namespace Torgash\Checkout\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A module's own rows about orders — a tax snapshot, a promotion's lines —
 * read for a whole page of orders in ONE query when checkout announces the
 * page (`checkout.orders.listed`), instead of one query per order when each
 * order is printed. The API's order list is such a page; the rule is the one
 * `catalog.products.listed` already lives by.
 *
 * One memo per table, keyed by order id. An order the page never announced is
 * read on its own, as before; an order recalculated in this request is
 * forgotten (its rows were just rewritten). Registered as a per-request
 * singleton and forgotten when the request is rebound, never a static.
 */
class OrderRows
{
    /** @var array<string, array<int, Collection<int, object>>> table => order id => rows */
    protected array $rows = [];

    /**
     * @param  list<int>  $orderIds
     * @param  callable(\Illuminate\Database\Query\Builder): mixed|null  $narrow  the same narrowing `for()` is asked with
     */
    public function prime(string $table, array $orderIds, ?callable $narrow = null): void
    {
        if ($orderIds === []) {
            return;
        }

        $query = DB::table($table)->whereIn('order_id', $orderIds)->orderBy('id');
        if ($narrow) {
            $narrow($query);
        }

        $found = $query->get()->groupBy('order_id');

        foreach ($orderIds as $id) {
            $this->rows[$table][$id] = $found->get($id, collect())->values();
        }
    }

    /** @return Collection<int, object> */
    public function for(string $table, int $orderId, ?callable $narrow = null): Collection
    {
        if (isset($this->rows[$table][$orderId])) {
            return $this->rows[$table][$orderId];
        }

        $query = DB::table($table)->where('order_id', $orderId)->orderBy('id');
        if ($narrow) {
            $narrow($query);
        }

        return $query->get();
    }

    public function forget(int $orderId): void
    {
        foreach ($this->rows as $table => $byOrder) {
            unset($this->rows[$table][$orderId]);
        }
    }
}
