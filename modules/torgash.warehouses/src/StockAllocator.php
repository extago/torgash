<?php

namespace Torgash\Warehouses;

use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;

/**
 * The one place a split product's stock is actually moved between warehouses.
 * The usual allocation — greedy,
 * priority-ordered, and never blocks a sale: if every warehouse runs dry the
 * last one in line goes negative rather than refusing the deduction, exactly
 * the (already-existing, pre-this-module) behaviour of the plain `stock`
 * column, which a raw ->decrement() has never floored at zero either.
 */
class StockAllocator
{
    /** @var array<int, bool> product_id => is split, for this request only */
    protected array $splitMemo = [];

    /** @var array<int, true> ids announced by prime() but not looked up yet */
    protected array $pending = [];

    /** @var array<int, array<int, int|null>> warehouse_id => product_id => qty here (null: not split) */
    protected array $atMemo = [];

    /** A product counts as "split" purely by having a row here — see the migration's docblock. */
    public function isSplit(int $productId): bool
    {
        if (isset($this->splitMemo[$productId])) {
            return $this->splitMemo[$productId];
        }

        if ($this->pending !== []) {
            $this->loadPending($productId);

            return $this->splitMemo[$productId];
        }

        return $this->splitMemo[$productId] =
            DB::table('m_warehouse_product_stock')->where('product_id', $productId)->exists();
    }

    /**
     * Announces a page's product set so isSplit() can answer for all of it in
     * one query instead of one per product.
     *
     * The hooks this module hangs off (stock_locked, stock_extra, availability)
     * are per-product by nature — a listing calls them once per row, which was
     * one `exists` query per row.
     *
     * Deliberately does NOT query: a listing that never asks about stock (every
     * storefront grid) would otherwise pay for a lookup nobody reads. The ids
     * are remembered, and the first isSplit() that misses fetches the lot.
     *
     * @param  iterable<int>  $productIds
     */
    public function prime(iterable $productIds): void
    {
        foreach ($productIds as $id) {
            $id = (int) $id;
            if (! isset($this->splitMemo[$id])) {
                $this->pending[$id] = true;
            }
        }
    }

    /**
     * How many of this product the given warehouse holds — the number behind
     * "in stock at the shop you picked".
     *
     * Answered for a whole grid at once: the ids a listing announces through
     * catalog.products.listed are fetched on the first question and the rest
     * are then free. Without that this is one query per card, which is exactly
     * the N+1 the withCardData() rule exists to prevent (CLAUDE.md).
     *
     * A product with no per-warehouse rows returns null, not 0: its stock lives
     * in the plain column and is available wherever the rest is, which is a
     * different answer from "this shelf is empty".
     */
    public function qtyAt(int $warehouseId, int $productId): ?int
    {
        if (! isset($this->atMemo[$warehouseId]) || ! array_key_exists($productId, $this->atMemo[$warehouseId])) {
            $this->loadAt($warehouseId, $productId);
        }

        return $this->atMemo[$warehouseId][$productId];
    }

    /** One query for the announced set (plus the id just asked for). */
    protected function loadAt(int $warehouseId, int $alsoInclude): void
    {
        $ids = array_values(array_unique(array_merge(
            array_keys($this->pending),
            array_keys($this->atMemo[$warehouseId] ?? []),
            [$alsoInclude],
        )));

        $rows = DB::table('m_warehouse_product_stock')
            ->whereIn('product_id', $ids)
            ->get(['product_id', 'warehouse_id', 'qty']);

        $here = [];
        $split = [];

        foreach ($rows as $row) {
            $split[(int) $row->product_id] = true;

            if ((int) $row->warehouse_id === $warehouseId) {
                $here[(int) $row->product_id] = (int) $row->qty;
            }
        }

        foreach ($ids as $id) {
            // Not split → null ("everywhere"); split but no row here → 0.
            $this->atMemo[$warehouseId][$id] = isset($split[$id]) ? ($here[$id] ?? 0) : null;

            // The same rows answer isSplit(), so the pending set is settled too
            // rather than paying for a second query over the same page.
            if (! isset($this->splitMemo[$id])) {
                $this->splitMemo[$id] = isset($split[$id]);
            }

            unset($this->pending[$id]);
        }
    }

    /** One query for every announced id (plus the one just asked for). */
    protected function loadPending(int $alsoInclude): void
    {
        $ids = array_keys($this->pending + [$alsoInclude => true]);
        $this->pending = [];

        // array_flip over intval, not in_array: pdo_pgsql hands integer columns
        // back as strings, so a strict search would report every product unsplit.
        $split = array_flip(DB::table('m_warehouse_product_stock')
            ->whereIn('product_id', $ids)
            ->distinct()->pluck('product_id')->map('intval')->all());

        foreach ($ids as $id) {
            $this->splitMemo[$id] = isset($split[$id]);
        }
    }

    /**
     * @param  array<int,int>|null  $restore  A previous deduct()'s return value, to
     *                                        reverse it exactly rather than re-running priority allocation.
     * @param  int|null  $prefer  take from this warehouse first if it can — how
     *                            "keep the order in one place" and, later, "collected from that
     *                            shop" both reach the allocation.
     * @return array<int,int>|null warehouse_id => applied delta (negative for a
     *                             deduction), or null when this product isn't split — the caller
     *                             (Product::adjustStock) falls back to a plain column update.
     */
    public function adjust(int $productId, int $delta, ?array $restore = null, ?int $prefer = null): ?array
    {
        if ($restore !== null) {
            // Naming warehouses only means something for a product whose stock
            // is actually kept per warehouse. For an unsplit one the aggregate
            // column IS the count, and creating a row here would make the row
            // the count instead — syncAggregate would then overwrite the column
            // with just these units and quietly discard everything it said.
            // Null sends the caller back to the plain column update.
            if (! $this->isSplit($productId)) {
                return null;
            }

            return DB::transaction(fn () => $this->restore($productId, $restore));
        }

        if (! $this->isSplit($productId)) {
            return null;
        }

        return DB::transaction(fn () => $delta < 0
            ? $this->deduct($productId, -$delta, $prefer)
            : $this->add($productId, $delta));
    }

    /**
     * One warehouse that can cover this whole set on its own, or null.
     *
     * The point is not optimisation, it is parcels: an order filled from three
     * warehouses is three pickings and, wherever the warehouses ship
     * separately, three deliveries. Almost every shop would rather send one box
     * from a warehouse further down the priority list than split the order —
     * and the ones that would not are the ones with a single dispatch point,
     * where consolidating costs nothing anyway.
     *
     * Highest priority first among those that CAN cover it, so the setting the
     * owner already made still decides between equals. Products that are not
     * split live in the plain `stock` column and are available wherever the
     * rest is, so they do not constrain the choice.
     *
     * @param  array<int, int>  $needs  product_id => quantity
     */
    public function covering(array $needs): ?int
    {
        foreach ($this->coveringAll($needs) as $warehouseId => $covers) {
            if ($covers) {
                return $warehouseId;
            }
        }

        return null;
    }

    /**
     * The same question asked of named warehouses: which of these could fill
     * the whole set on their own.
     *
     * This is the pickup picker's gate. Consolidation is a preference — a
     * warehouse that cannot cover a line just shares it with the next one — but
     * "collect it from this shop" is a promise, and a shop that has two of the
     * three items must not be on the list at all (PLAN §20.2/3). Checking here,
     * while the list is built, is what makes the promise keepable; checking at
     * deduction time would only discover it after the order was placed.
     *
     * @param  array<int, int>  $needs  product_id => quantity
     * @param  list<int>  $warehouseIds
     * @return list<int> those that cover it, priority order
     */
    public function coveringAmong(array $needs, array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }

        $covers = $this->coveringAll($needs, $warehouseIds);

        // Nothing in the set is stocked per warehouse: every warehouse has it
        // (see coveringAll), so the answer is "all of them", in the order asked.
        if ($covers === []) {
            return array_values(array_map('intval', $warehouseIds));
        }

        return array_values(array_keys(array_filter($covers)));
    }

    /**
     * warehouse_id => can it cover the whole set, in priority order. Empty when
     * the set constrains nothing — a product with no per-warehouse rows lives
     * in the plain `stock` column and is therefore available wherever the rest
     * is, so it never rules a warehouse out.
     *
     * @param  array<int, int>  $needs  product_id => quantity
     * @param  list<int>|null  $only  restrict to these warehouses
     * @return array<int, bool>
     */
    protected function coveringAll(array $needs, ?array $only = null): array
    {
        $this->prime(array_keys($needs));

        $needs = array_filter(
            $needs,
            fn (int $qty, int $productId) => $qty > 0 && $this->isSplit($productId),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($needs === []) {
            return [];
        }

        $rows = DB::table('m_warehouse_product_stock as s')
            ->join('m_warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('w.enabled', true)
            ->when($only !== null, fn ($query) => $query->whereIn('s.warehouse_id', $only))
            ->whereIn('s.product_id', array_keys($needs))
            ->orderBy('w.sort')
            ->get(['s.warehouse_id', 's.product_id', 's.qty']);

        // Grouped in the order the query returned them, so iterating the groups
        // is iterating by priority.
        $held = [];

        foreach ($rows as $row) {
            $held[(int) $row->warehouse_id][(int) $row->product_id] = (int) $row->qty;
        }

        $covers = [];

        foreach ($held as $warehouseId => $stock) {
            $covers[$warehouseId] = true;

            foreach ($needs as $productId => $qty) {
                if (($stock[$productId] ?? 0) < $qty) {
                    $covers[$warehouseId] = false;

                    break;
                }
            }
        }

        return $covers;
    }

    /**
     * Drains enabled warehouses in priority order; the last one in line may go
     * negative.
     *
     * `$prefer` jumps one warehouse to the front without changing anything else
     * — it is a preference, not a command: if it cannot cover the line the rest
     * of the priority order still fills the gap, and the lowest-priority
     * warehouse is still the one that goes negative when everything runs dry.
     * That is what lets "keep this order in one warehouse" and, later, "this
     * order is collected from that shop" be the same mechanism.
     */
    protected function deduct(int $productId, int $amount, ?int $prefer = null): array
    {
        $rows = DB::table('m_warehouse_product_stock as s')
            ->join('m_warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('s.product_id', $productId)
            ->orderByRaw('case when s.warehouse_id = ? then 0 else 1 end', [$prefer ?? 0])
            ->orderByDesc('w.enabled')
            ->orderBy('w.sort')
            ->lockForUpdate()
            ->get(['s.warehouse_id', 's.qty']);

        if ($rows->isEmpty()) {
            return [];
        }

        $remaining = $amount;
        $applied = [];
        $lastIndex = $rows->count() - 1;

        foreach ($rows as $i => $row) {
            if ($remaining <= 0) {
                break;
            }

            $take = $i === $lastIndex ? $remaining : min($remaining, max($row->qty, 0));

            if ($take === 0) {
                continue;
            }

            DB::table('m_warehouse_product_stock')
                ->where('warehouse_id', $row->warehouse_id)->where('product_id', $productId)
                ->decrement('qty', $take);
            $this->moved($productId, (int) $row->warehouse_id, -$take, (int) $row->qty - $take);

            $applied[$row->warehouse_id] = ($applied[$row->warehouse_id] ?? 0) - $take;
            $remaining -= $take;
        }

        $this->syncAggregate($productId);

        return $applied;
    }

    /** No specific warehouse requested — restock the highest-priority enabled one. */
    protected function add(int $productId, int $amount): array
    {
        $warehouseId = DB::table('m_warehouse_product_stock as s')
            ->join('m_warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('s.product_id', $productId)
            ->where('w.enabled', true)
            ->orderBy('w.sort')
            ->lockForUpdate()
            ->value('s.warehouse_id');

        if ($warehouseId === null) {
            return [];
        }

        DB::table('m_warehouse_product_stock')
            ->where('warehouse_id', $warehouseId)->where('product_id', $productId)
            ->increment('qty', $amount);
        $this->moved($productId, (int) $warehouseId, $amount);

        $this->syncAggregate($productId);

        return [$warehouseId => $amount];
    }

    /**
     * Credits exactly the warehouses a prior deduct() took from. A warehouse
     * that no longer has a stock row for this product (deleted since) doesn't
     * silently lose those units — they land on the default warehouse instead.
     *
     * Two reasons a row can be missing, and they want opposite answers. The
     * warehouse is GONE (cascade took its rows with it): the units have to go
     * somewhere, and the default is the only defensible guess. The warehouse is
     * still there and has simply never held this product: that is a returned
     * parcel arriving at a shop that never stocked the thing — the goods are
     * physically on that shelf, so the row is created there rather than the
     * credit being quietly moved to a warehouse in another city.
     *
     * @param  array<int,int>  $restore  warehouse_id => previously applied delta (negative)
     */
    protected function restore(int $productId, array $restore): array
    {
        $applied = [];

        foreach ($restore as $warehouseId => $delta) {
            $amount = -$delta;
            if ($amount <= 0) {
                continue;
            }

            // increment() is its own atomic single-row UPDATE — no SELECT ... FOR
            // UPDATE needed first, unlike deduct()'s allocation loop below, which
            // has to read several rows before deciding how much each one loses.
            $updated = DB::table('m_warehouse_product_stock')
                ->where('warehouse_id', $warehouseId)->where('product_id', $productId)
                ->increment('qty', $amount);

            if ($updated) {
                $applied[$warehouseId] = $amount;
                $this->moved($productId, (int) $warehouseId, $amount);

                continue;
            }

            if (DB::table('m_warehouses')->where('id', $warehouseId)->exists()) {
                $this->credit($warehouseId, $productId, $amount);
                $applied[$warehouseId] = ($applied[$warehouseId] ?? 0) + $amount;

                continue;
            }

            $fallback = $this->creditDefaultWarehouse($productId, $amount);
            if ($fallback !== null) {
                $applied[$fallback] = ($applied[$fallback] ?? 0) + $amount;
            }
        }

        $this->syncAggregate($productId);

        return $applied;
    }

    protected function creditDefaultWarehouse(int $productId, int $amount): ?int
    {
        $warehouseId = DB::table('m_warehouses')->where('is_default', true)->value('id')
            ?? DB::table('m_warehouses')->orderBy('sort')->value('id');

        if ($warehouseId === null) {
            return null;
        }

        $this->credit($warehouseId, $productId, $amount);

        return $warehouseId;
    }

    /**
     * Add units to one named warehouse, creating its row for this product if
     * there is none. Insert-then-increment rather than an updateOrInsert
     * carrying the amount: two of these racing must add up, not overwrite.
     */
    protected function credit(int $warehouseId, int $productId, int $amount): void
    {
        DB::table('m_warehouse_product_stock')->updateOrInsert(
            ['warehouse_id' => $warehouseId, 'product_id' => $productId],
            [],
        );

        DB::table('m_warehouse_product_stock')
            ->where('warehouse_id', $warehouseId)->where('product_id', $productId)
            ->increment('qty', $amount);
        $this->moved($productId, $warehouseId, $amount);
    }

    /**
     * Set one warehouse's quantity of a product outright — the stock screen,
     * an inventory count, 1C — and announce the difference. Splits a product
     * that was not split yet: its own column's units go to the default
     * warehouse first (announced as leaving the column and arriving there),
     * so nothing typed on the product before is lost.
     */
    public function set(int $productId, int $warehouseId, int $qty): void
    {
        DB::transaction(function () use ($productId, $warehouseId, $qty) {
            $this->split($productId, $warehouseId);

            $was = (int) DB::table('m_warehouse_product_stock')
                ->where('warehouse_id', $warehouseId)->where('product_id', $productId)->lockForUpdate()->value('qty');

            DB::table('m_warehouse_product_stock')->updateOrInsert(
                ['warehouse_id' => $warehouseId, 'product_id' => $productId],
                ['qty' => $qty],
            );
            $this->moved($productId, $warehouseId, $qty - $was, $qty);

            $this->syncAggregate($productId);
        });
    }

    /**
     * Move `$delta` units of a product at ONE warehouse — a receipt, a
     * write-off, one half of a transfer. Unlike `adjust()` it never spills to
     * another warehouse: a document names where the goods are. Returns what
     * the row holds after.
     */
    public function move(int $productId, int $warehouseId, int $delta): int
    {
        return DB::transaction(function () use ($productId, $warehouseId, $delta) {
            $this->split($productId, $warehouseId);

            DB::table('m_warehouse_product_stock')->updateOrInsert(
                ['warehouse_id' => $warehouseId, 'product_id' => $productId],
                [],
            );
            DB::table('m_warehouse_product_stock')
                ->where('warehouse_id', $warehouseId)->where('product_id', $productId)
                ->increment('qty', $delta);

            $after = (int) DB::table('m_warehouse_product_stock')
                ->where('warehouse_id', $warehouseId)->where('product_id', $productId)->value('qty');
            $this->moved($productId, $warehouseId, $delta, $after);

            $this->syncAggregate($productId);

            return $after;
        });
    }

    /**
     * A product not kept per warehouse yet gets its first row: what its own
     * column held goes to the default warehouse (else the one being written),
     * so the column's units do not vanish the moment a warehouse is named.
     */
    protected function split(int $productId, int $warehouseId): void
    {
        if ($this->isSplit($productId)) {
            return;
        }

        $held = (int) DB::table('m_catalog_products')->where('id', $productId)->value('stock');

        if ($held !== 0) {
            $home = (int) (DB::table('m_warehouses')->where('is_default', true)->value('id') ?? $warehouseId);

            DB::table('m_warehouse_product_stock')->insert(['warehouse_id' => $home, 'product_id' => $productId, 'qty' => $held]);

            // The same units, now counted at a warehouse instead of on the product.
            $moves = app(\Torgash\Catalog\Stock\StockMoves::class);
            $moves->record($productId, null, -$held, 0, ['type' => 'split']);
            $moves->record($productId, $home, $held, $held, ['type' => 'split']);
        }

        unset($this->splitMemo[$productId], $this->pending[$productId]);
        $this->splitMemo[$productId] = true;
    }

    /** Announce a warehouse row's movement (PLAN §42); what it means is not ours. */
    protected function moved(int $productId, int $warehouseId, int $delta, ?int $after = null): void
    {
        $after ??= (int) DB::table('m_warehouse_product_stock')
            ->where('warehouse_id', $warehouseId)->where('product_id', $productId)->value('qty');

        app(\Torgash\Catalog\Stock\StockMoves::class)->record($productId, $warehouseId, $delta, $after);
    }

    /**
     * Keeps m_catalog_products.stock equal to the sum of this product's
     * warehouse rows. Every mutation path ends here, so it is also where the
     * isSplit() memo is dropped: creditDefaultWarehouse() can turn an unsplit
     * product into a split one mid-request.
     */
    protected function syncAggregate(int $productId): void
    {
        unset($this->splitMemo[$productId], $this->pending[$productId]);

        foreach ($this->atMemo as $warehouseId => $_) {
            unset($this->atMemo[$warehouseId][$productId]);
        }

        $sum = (int) DB::table('m_warehouse_product_stock')->where('product_id', $productId)->sum('qty');

        // The catalogue writes its own column: the number is ours to compute
        // and its MEANING is theirs — whether the product can be bought again,
        // and whether reaching zero closes the sale. A raw update here fired no
        // event at all, so a delivery arriving was the one stock change nothing
        // downstream could hear (Product::syncStockTo).
        Product::syncStockTo($productId, $sum);
    }
}
