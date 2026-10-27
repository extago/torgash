<?php

namespace Torgash\Warehouses;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "We will bring it by 24.09" (PLAN §38): when the shop the customer chose
 * does not hold a product, the soonest another warehouse that DOES hold it
 * can get it there — its `transfer_days`, counted from today, over working
 * days when the shop says so.
 *
 * One number per product for the whole page: the grid announces its products
 * (catalog.products.listed → prime()) and the first question fetches all of
 * them in one query, the rule StockAllocator lives by. A registered
 * per-request singleton, never a static.
 */
class Transfers
{
    /** @var array<int, true> */
    protected array $pending = [];

    /** @var array<string, array<int, int|null>> point id => product id => days */
    protected array $memo = [];

    /** @param  iterable<int>  $productIds */
    public function prime(iterable $productIds): void
    {
        foreach ($productIds as $id) {
            $this->pending[(int) $id] = true;
        }
    }

    /** Days until it can be at the point, or null when no warehouse can send it. */
    public function days(int $productId, int $pointId): ?int
    {
        $key = (string) $pointId;

        if (! isset($this->memo[$key]) || ! array_key_exists($productId, $this->memo[$key])) {
            $ids = array_keys($this->pending + [$productId => true]);
            $found = DB::table('m_warehouse_product_stock as s')
                ->join('m_warehouses as w', 'w.id', '=', 's.warehouse_id')
                ->whereIn('s.product_id', $ids)
                ->where('s.qty', '>', 0)
                ->where('w.enabled', true)
                ->whereNotNull('w.transfer_days')
                ->where('w.id', '!=', $pointId)
                ->groupBy('s.product_id')
                ->selectRaw('s.product_id, min(w.transfer_days) as days')
                ->pluck('days', 'product_id');

            foreach ($ids as $id) {
                $this->memo[$key][$id] = isset($found[$id]) ? (int) $found[$id] : null;
            }
        }

        return $this->memo[$key][$productId];
    }

    /** The day itself — working days only when the shop counts that way. */
    public function date(int $productId, int $pointId, ?CarbonImmutable $from = null): ?CarbonImmutable
    {
        $days = $this->days($productId, $pointId);

        if ($days === null) {
            return null;
        }

        $date = $from ?? CarbonImmutable::today();

        return setting('torgash.warehouses', 'transfer_working_days') ? $date->addWeekdays($days) : $date->addDays($days);
    }
}
