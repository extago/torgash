<?php

namespace Torgash\Inventory;

use Illuminate\Support\Facades\DB;
use Torgash\Inventory\Models\Document;

/**
 * What each line of a stock document is WORTH (PLAN §42), for the paper and
 * the screen: a receipt at the price typed on it; a write-off, a transfer or
 * a count's difference at the cost of the batches it took — exactly, once
 * posted (the parts the journal wrote), and as an ESTIMATE on a draft (the
 * batches that would be taken now, oldest first), which the sheet says.
 * Units no batch prices are counted, never valued at zero.
 */
class DocumentValue
{
    /**
     * @return array<int, array{unit: ?int, amount: ?int, unknown: int, currency: string, estimate: bool}> by line id
     */
    public function lines(Document $document): array
    {
        $out = [];

        foreach ($document->lines as $line) {
            if ($document->type === Document::RECEIPT) {
                $posted = $document->isPosted() && $line->unit_cost !== null;
                $unit = $posted ? (int) $line->unit_cost : $line->unit_cost_original;
                $out[$line->id] = [
                    'unit' => $unit,
                    'amount' => $unit === null ? null : $unit * (int) $line->qty,
                    'unknown' => $unit === null ? (int) $line->qty : 0,
                    'currency' => $posted ? shop_currency() : ($line->currency ?: shop_currency()),
                    'estimate' => false,
                ];

                continue;
            }

            [$amount, $known, $unknown] = $document->isPosted()
                ? $this->taken($document->id, (int) $line->product_id)
                : $this->wouldTake((int) $line->product_id, (int) $document->warehouse_id, $this->outgoing($document, $line));

            $out[$line->id] = [
                'unit' => $known > 0 ? (int) round($amount / $known) : null,
                'amount' => $known > 0 ? $amount : null,
                'unknown' => $unknown,
                'currency' => shop_currency(),
                'estimate' => ! $document->isPosted(),
            ];
        }

        return $out;
    }

    /** How many units a line takes out: all of them, or a count's shortfall. */
    protected function outgoing(Document $document, $line): int
    {
        if ($document->type !== Document::COUNT) {
            return (int) $line->qty;
        }

        $held = (int) (DB::table('m_warehouse_product_stock')->where('product_id', $line->product_id)->where('warehouse_id', $document->warehouse_id)->value('qty') ?? 0);

        return max(0, $held - (int) $line->qty);
    }

    /** @return array{0: int, 1: int, 2: int} amount, units priced, units unpriced — what the posting actually took */
    protected function taken(int $documentId, int $productId): array
    {
        $row = DB::table('m_inventory_move_costs as c')
            ->join('m_inventory_moves as m', 'm.id', '=', 'c.move_id')
            ->where('m.document_id', $documentId)->where('m.product_id', $productId)->where('m.delta', '<', 0)
            ->selectRaw('coalesce(sum(c.qty * c.unit_cost), 0) as amount')
            ->selectRaw('coalesce(sum(case when c.unit_cost is not null then c.qty else 0 end), 0) as known')
            ->selectRaw('coalesce(sum(case when c.unit_cost is null then c.qty else 0 end), 0) as unknown')
            ->first();

        return [(int) $row->amount, (int) $row->known, (int) $row->unknown];
    }

    /** @return array{0: int, 1: int, 2: int} what taking `$qty` now would cost, oldest batches first */
    protected function wouldTake(int $productId, int $warehouseId, int $qty): array
    {
        $amount = $known = $unknown = 0;

        // A product not split yet keeps its batches on its own column (NULL),
        // which posting moves to the warehouse first — so those count too.
        $batches = DB::table('m_inventory_batches')->where('product_id', $productId)
            ->where(fn ($q) => $q->where('warehouse_id', $warehouseId)->orWhereNull('warehouse_id'))
            ->where('remaining', '>', 0)->orderBy('id')->get(['remaining', 'unit_cost']);

        foreach ($batches as $batch) {
            if ($qty <= 0) {
                break;
            }

            $part = min($qty, (int) $batch->remaining);
            if ($batch->unit_cost === null) {
                $unknown += $part;
            } else {
                $amount += $part * (int) $batch->unit_cost;
                $known += $part;
            }
            $qty -= $part;
        }

        return [$amount, $known, $unknown + max(0, $qty)];
    }
}
