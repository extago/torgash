<?php

namespace Torgash\Inventory;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What an order earned (PLAN §42): the goods after their discounts plus the
 * delivery charged, minus what the goods cost (their batches, FIFO), minus the
 * order's own expenses, plus its extra income. Taxes are not taken off — the
 * report says so — and units nobody priced are COUNTED, never costed at zero.
 *
 * One class for the order's own card and the period's report, each a handful
 * of grouped queries whatever the number of orders.
 */
class Profit
{
    /**
     * @param  list<int>  $orderIds
     * @return array<int, array{goods: int, delivery: int, cost: int, unknown: int, expenses: int, income: int, profit: int}>
     */
    public function forOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $orders = DB::table('m_checkout_orders')->whereIn('id', $orderIds)
            ->get(['id', 'subtotal', 'discount_amount', 'shipping_cost'])->keyBy('id');
        $costs = $this->costs(fn (Builder $q) => $q->whereIn(DB::raw(self::OWNER), $orderIds))->keyBy('order_id');
        $own = $this->own(fn (Builder $q) => $q->whereIn('order_id', $orderIds))->keyBy('order_id');

        $out = [];
        foreach ($orderIds as $id) {
            $order = $orders->get($id);
            if (! $order) {
                continue;
            }

            $out[$id] = $this->line(
                (int) $order->subtotal - (int) $order->discount_amount,
                (int) $order->shipping_cost,
                $costs->get($id),
                $own->get($id),
            );
        }

        return $out;
    }

    /**
     * The same numbers summed over every order a query selects.
     *
     * @return array{goods: int, delivery: int, cost: int, unknown: int, expenses: int, income: int, profit: int, orders: int}
     */
    public function totals(Builder $orders): array
    {
        $ids = (clone $orders)->select('m_checkout_orders.id');

        $sums = (clone $orders)->selectRaw('count(*) as n, coalesce(sum(subtotal - discount_amount), 0) as goods, coalesce(sum(shipping_cost), 0) as delivery')->first();
        $cost = $this->costs(fn (Builder $q) => $q->whereIn(DB::raw(self::OWNER), $ids), grouped: false)->first();
        $own = $this->own(fn (Builder $q) => $q->whereIn('order_id', $ids), grouped: false)->first();

        return $this->line((int) $sums->goods, (int) $sums->delivery, $cost, $own) + ['orders' => (int) $sums->n];
    }

    /** What an order's labels have been, most used first — the form's suggestions. */
    public function labels(): array
    {
        return DB::table('m_inventory_order_costs')->selectRaw('label, count(*) as n')
            ->groupBy('label')->orderByDesc('n')->limit(30)->pluck('label')->all();
    }

    protected function line(int $goods, int $delivery, ?object $cost, ?object $own): array
    {
        $line = [
            'goods' => $goods,
            'delivery' => $delivery,
            'cost' => (int) ($cost->cost ?? 0),
            'unknown' => (int) ($cost->unknown ?? 0),
            'expenses' => (int) ($own->expenses ?? 0),
            'income' => (int) ($own->income ?? 0),
        ];

        return $line + ['profit' => $goods + $delivery - $line['cost'] - $line['expenses'] + $line['income']];
    }

    /**
     * The order a movement's cost belongs to: its own, or the one it was
     * poured into (PLAN §40.10/4) — B's goods were taken for B and are A's
     * now, so their cost is A's.
     */
    protected const OWNER = 'coalesce(o.merged_into_id, o.id)';

    /** Sales take units (negative movements), returns give them back. */
    protected function costs(callable $narrow, bool $grouped = true)
    {
        $query = DB::table('m_inventory_move_costs as c')
            ->join('m_inventory_moves as m', 'm.id', '=', 'c.move_id')
            ->join('m_checkout_orders as o', 'o.id', '=', 'm.order_id')
            ->selectRaw('coalesce(sum(case when c.unit_cost is null then 0 when m.delta < 0 then c.qty * c.unit_cost else -c.qty * c.unit_cost end), 0) as cost')
            ->selectRaw('coalesce(sum(case when c.unit_cost is not null then 0 when m.delta < 0 then c.qty else -c.qty end), 0) as unknown');
        $narrow($query);

        return $grouped ? $query->selectRaw(self::OWNER.' as order_id')->groupByRaw(self::OWNER)->get() : $query->get();
    }

    protected function own(callable $narrow, bool $grouped = true)
    {
        $query = DB::table('m_inventory_order_costs')
            ->selectRaw("coalesce(sum(case when kind = 'expense' then amount else 0 end), 0) as expenses")
            ->selectRaw("coalesce(sum(case when kind = 'income' then amount else 0 end), 0) as income");
        $narrow($query);

        return $grouped ? $query->addSelect('order_id')->groupBy('order_id')->get() : $query->get();
    }
}
