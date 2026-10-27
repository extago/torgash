<?php

namespace Torgash\Warehouses\Invariants;

use Illuminate\Support\Facades\DB;

/**
 * What must be true about stock, said by the module that keeps it.
 *
 * Answered lazily through `guard.invariants` — no dependency either way, and
 * the guard never learns this module's tables.
 *
 * Both statements are about the same thing from two sides: a warehouse row that
 * says something impossible, and a total that no longer agrees with the rows it
 * is the sum of. The second is the one nobody would ever notice by looking: the
 * shop keeps selling from a number that is not what is on the shelves.
 */
class WarehouseInvariants
{
    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            'warehouses.stock_not_negative' => [
                'label' => 'No warehouse holds a negative quantity',
                'explain' => 'Something took more off the shelf than was on it — an order shipped twice, or a status that decreases stock applied twice. Correct the count on the warehouse\'s stock screen, then look at that product\'s recent orders.',
                'check' => fn (int $limit) => DB::table('m_warehouse_product_stock as s')
                    ->join('m_warehouses as w', 'w.id', '=', 's.warehouse_id')
                    ->leftJoin('m_catalog_products as p', 'p.id', '=', 's.product_id')
                    ->where('s.qty', '<', 0)
                    ->limit($limit)
                    ->get(['s.product_id', 's.warehouse_id', 's.qty', 'w.name as warehouse', 'p.sku'])
                    ->map(fn ($row) => [
                        'id' => $row->warehouse_id.':'.$row->product_id,
                        'title' => $row->sku ?: '#'.$row->product_id,
                        'detail' => [':warehouse: :qty', ['warehouse' => $row->warehouse, 'qty' => $row->qty]],
                        'url' => route('admin.warehouses.stock', $row->warehouse_id),
                    ]),
            ],

            'warehouses.total_matches_warehouses' => [
                'label' => 'A product kept in warehouses has a total equal to the sum of them',
                'explain' => 'The product\'s own stock figure is what the storefront sells from, and it is meant to be the sum of the warehouse rows. They have drifted apart, so the shop is selling from a number that is not what is on the shelves. Saving the stock screen for any of those warehouses recomputes the total.',
                'check' => fn (int $limit) => DB::table('m_warehouse_product_stock as s')
                    ->join('m_catalog_products as p', 'p.id', '=', 's.product_id')
                    ->whereNull('p.deleted_at')
                    ->groupBy('s.product_id', 'p.sku', 'p.stock')
                    ->havingRaw('sum(s.qty) <> p.stock')
                    ->limit($limit)
                    ->get(['s.product_id', 'p.sku', 'p.stock', DB::raw('sum(s.qty) as warehouse_sum')])
                    ->map(fn ($row) => [
                        'id' => (string) $row->product_id,
                        'title' => $row->sku ?: '#'.$row->product_id,
                        'detail' => ['Total :total, warehouses hold :sum', [
                            'total' => $row->stock,
                            'sum' => $row->warehouse_sum,
                        ]],
                        'url' => route('admin.catalog.products.edit', $row->product_id),
                    ]),
            ],
        ];
    }
}
