<?php

namespace Torgash\Inventory;

use Illuminate\Support\Facades\DB;

/**
 * What every movement COST (PLAN §42): batches at their purchase price, taken
 * oldest first.
 *
 * Asked by the journal right after it writes a movement, so it sees exactly
 * what the journal sees — whoever caused it. Units going OUT are taken from
 * the batches at that place, oldest first (FIFO), and each part is written
 * down with its price (`m_inventory_move_costs`); where no batch has any left
 * the part has no batch and no price. Units coming IN are a new batch, and
 * what it cost depends on why they came:
 *
 * - a receipt: the price frozen on the document's line;
 * - a transfer or a split: the batches the other half just took, price for
 *   price — goods moved, their cost did not change;
 * - an order giving goods back (a return, a cancellation, a merge undone):
 *   back into the very batches that order took them from, most recent first,
 *   never more than the order took and has not had back already;
 * - anything else (an import, 1C, a number typed, a count's surplus): an
 *   UNKNOWN price, which the profit report says rather than guessing.
 */
class Batches
{
    /** @param  array<string, mixed>  $move  as announced, with the journal row's id */
    public function apply(int $moveId, array $move): void
    {
        $delta = (int) $move['delta'];
        $productId = (int) $move['product_id'];
        $warehouseId = $move['warehouse_id'] ?? null;
        $cause = (array) ($move['cause'] ?? []);

        if ($delta < 0) {
            $this->take($moveId, $productId, $warehouseId, -$delta);

            return;
        }

        $type = (string) ($cause['type'] ?? 'edit');

        match (true) {
            $type === 'document' && ($cause['kind'] ?? null) === 'receipt' => $this->receive($moveId, $productId, $warehouseId, $delta, (int) $cause['document_id']),
            ($type === 'document' && ($cause['kind'] ?? null) === 'transfer') || $type === 'split' => $this->carry($moveId, $productId, $warehouseId, $delta, $cause),
            in_array($type, ['order', 'return', 'merge'], true) && ! empty($cause['order_id']) => $this->giveBack($moveId, $productId, $warehouseId, $delta, (int) $cause['order_id']),
            default => $this->unknown($moveId, $productId, $warehouseId, $delta, $type === 'document' ? (string) ($cause['kind'] ?? 'document') : $type),
        };
    }

    /** Out: the oldest batches first; what no batch covers has no price. */
    protected function take(int $moveId, int $productId, ?int $warehouseId, int $qty): void
    {
        $batches = $this->at($productId, $warehouseId)->where('remaining', '>', 0)->orderBy('id')->lockForUpdate()->get();

        foreach ($batches as $batch) {
            if ($qty <= 0) {
                break;
            }

            $part = min($qty, (int) $batch->remaining);
            DB::table('m_inventory_batches')->where('id', $batch->id)->decrement('remaining', $part);
            $this->cost($moveId, $batch->id, $part, $batch->unit_cost);
            $qty -= $part;
        }

        if ($qty > 0) {
            $this->cost($moveId, null, $qty, null);
        }
    }

    protected function receive(int $moveId, int $productId, ?int $warehouseId, int $qty, int $documentId): void
    {
        $unitCost = DB::table('m_inventory_document_lines')
            ->where('document_id', $documentId)->where('product_id', $productId)
            ->value('unit_cost');

        $this->batch($moveId, $productId, $warehouseId, $qty, $unitCost === null ? null : (int) $unitCost, 'receipt', $documentId);
    }

    /**
     * In, as the other half of a transfer or a split: the parts the matching
     * movement out just took, each becoming a batch here at its own price.
     */
    protected function carry(int $moveId, int $productId, ?int $warehouseId, int $qty, array $cause): void
    {
        $source = DB::table('m_inventory_moves')
            ->where('product_id', $productId)
            ->where('delta', -$qty)
            ->where('id', '<', $moveId)
            ->when(($cause['type'] ?? null) === 'split',
                fn ($query) => $query->where('type', 'split'),
                fn ($query) => $query->where('document_id', $cause['document_id'] ?? 0))
            ->orderByDesc('id')
            ->value('id');

        $parts = $source ? DB::table('m_inventory_move_costs')->where('move_id', $source)->orderBy('id')->get() : collect();
        $placed = 0;

        foreach ($parts as $part) {
            $this->batch($moveId, $productId, $warehouseId, (int) $part->qty, $part->unit_cost === null ? null : (int) $part->unit_cost, 'transfer', $cause['document_id'] ?? null);
            $placed += (int) $part->qty;
        }

        if ($qty > $placed) {
            $this->batch($moveId, $productId, $warehouseId, $qty - $placed, null, 'transfer', $cause['document_id'] ?? null);
        }
    }

    /**
     * In, because an order gave goods back: into the batches that order took
     * them from, the most recently taken first, never more than it took and
     * has not had back already. A batch at another place than where the goods
     * came back to is not refilled — the units become a batch here at that
     * batch's price.
     */
    protected function giveBack(int $moveId, int $productId, ?int $warehouseId, int $qty, int $orderId): void
    {
        $taken = DB::table('m_inventory_move_costs as c')
            ->join('m_inventory_moves as m', 'm.id', '=', 'c.move_id')
            ->where('m.order_id', $orderId)->where('m.product_id', $productId)
            ->where('m.id', '<', $moveId)
            ->selectRaw('c.batch_id, c.unit_cost, sum(case when m.delta < 0 then c.qty else -c.qty end) as open, max(c.id) as last')
            ->groupBy('c.batch_id', 'c.unit_cost')
            ->havingRaw('sum(case when m.delta < 0 then c.qty else -c.qty end) > 0')
            ->orderByDesc('last')
            ->get();

        foreach ($taken as $part) {
            if ($qty <= 0) {
                break;
            }

            $back = min($qty, (int) $part->open);
            $unitCost = $part->unit_cost === null ? null : (int) $part->unit_cost;
            $batch = $part->batch_id ? DB::table('m_inventory_batches')->where('id', $part->batch_id)->first() : null;

            if ($batch && $this->samePlace($batch->warehouse_id, $warehouseId)) {
                DB::table('m_inventory_batches')->where('id', $batch->id)->increment('remaining', $back);
                $this->cost($moveId, $batch->id, $back, $unitCost);
            } else {
                $this->batch($moveId, $productId, $warehouseId, $back, $unitCost, 'return');
            }

            $qty -= $back;
        }

        if ($qty > 0) {
            $this->batch($moveId, $productId, $warehouseId, $qty, null, 'return');
        }
    }

    protected function unknown(int $moveId, int $productId, ?int $warehouseId, int $qty, string $source): void
    {
        $this->batch($moveId, $productId, $warehouseId, $qty, null, substr($source, 0, 16));
    }

    protected function batch(int $moveId, int $productId, ?int $warehouseId, int $qty, ?int $unitCost, string $source, ?int $documentId = null): void
    {
        $id = DB::table('m_inventory_batches')->insertGetId([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'qty' => $qty,
            'remaining' => $qty,
            'unit_cost' => $unitCost,
            'source' => $source,
            'document_id' => $documentId,
            'move_id' => $moveId,
            'created_at' => now(),
        ]);

        $this->cost($moveId, $id, $qty, $unitCost);
    }

    protected function cost(int $moveId, ?int $batchId, int $qty, $unitCost): void
    {
        DB::table('m_inventory_move_costs')->insert([
            'move_id' => $moveId,
            'batch_id' => $batchId,
            'qty' => $qty,
            'unit_cost' => $unitCost === null ? null : (int) $unitCost,
        ]);
    }

    protected function at(int $productId, ?int $warehouseId)
    {
        return DB::table('m_inventory_batches')->where('product_id', $productId)
            ->when($warehouseId === null, fn ($query) => $query->whereNull('warehouse_id'), fn ($query) => $query->where('warehouse_id', $warehouseId));
    }

    protected function samePlace($a, $b): bool
    {
        return ($a === null ? null : (int) $a) === ($b === null ? null : (int) $b);
    }
}
