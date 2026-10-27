<?php

namespace Torgash\Inventory;

use App\Core\Support\Currencies;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Stock\StockMoves;
use Torgash\Inventory\Models\Document;
use Torgash\Warehouses\StockAllocator;

/**
 * Posting a stock document (PLAN §42) — the one place a document moves stock,
 * once, inside one transaction, every movement written down as this document.
 *
 * A receipt adds at its warehouse, a write-off takes from it, a transfer
 * takes from one and adds to the other, a count SETS the warehouse to what
 * was counted and writes on the line what it held a moment before. Nothing
 * spills to another warehouse — a document names where the goods are — and a
 * write-off or a transfer of more than the warehouse holds is refused, line by
 * line, rather than driving the shelf negative. A receipt's price is frozen
 * here in the books at today's rate. After posting the document is closed; a
 * mistake is the next document's to correct.
 */
class Posting
{
    public function __construct(protected StockAllocator $allocator, protected StockMoves $moves) {}

    public function post(Document $document, ?User $by = null): void
    {
        DB::transaction(function () use ($document, $by) {
            $document = Document::lockForUpdate()->findOrFail($document->id);

            if (! $document->isDraft()) {
                throw ValidationException::withMessages(['document' => __('This document has already been posted.')]);
            }

            $lines = $document->lines()->get()->filter(fn ($line) => $document->type === Document::COUNT || $line->qty !== 0);

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['document' => __('There is nothing on this document to post.')]);
            }

            if ($document->type === Document::TRANSFER && (! $document->to_warehouse_id || $document->to_warehouse_id === $document->warehouse_id)) {
                throw ValidationException::withMessages(['to_warehouse_id' => __('Choose the warehouse the goods go to.')]);
            }

            $this->refuseShortages($document, $lines);

            $cause = ['type' => 'document', 'document_id' => $document->id, 'number' => $document->number, 'kind' => $document->type];

            $this->moves->because($cause, function () use ($document, $lines) {
                foreach ($lines as $line) {
                    match ($document->type) {
                        Document::RECEIPT => $this->receive($document, $line),
                        Document::WRITEOFF => $this->allocator->move($line->product_id, $document->warehouse_id, -$line->qty),
                        Document::TRANSFER => $this->transfer($document, $line),
                        Document::COUNT => $this->count($document, $line),
                    };
                }
            });

            $document->forceFill([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $by?->id,
                'posted_by_name' => $by?->name,
            ])->save();

            hook_action('inventory.document.posted', ['document' => $document]);
        });
    }

    /** Quantity out of a warehouse must be there: every short line named at once. */
    protected function refuseShortages(Document $document, $lines): void
    {
        if (! in_array($document->type, [Document::WRITEOFF, Document::TRANSFER], true)) {
            return;
        }

        $short = [];
        $need = [];
        foreach ($lines as $line) {
            $need[$line->product_id] = ($need[$line->product_id] ?? 0) + $line->qty;
        }

        foreach ($need as $productId => $qty) {
            $held = $this->held($productId, $document->warehouse_id);
            if ($qty > $held) {
                $sku = $lines->firstWhere('product_id', $productId)?->sku;
                $short[] = __(':sku: :need wanted, :held at this warehouse.', ['sku' => $sku, 'need' => $qty, 'held' => $held]);
            }
        }

        if ($short) {
            throw ValidationException::withMessages(['lines' => $short]);
        }
    }

    /**
     * What a warehouse holds of a product. A product not kept per warehouse
     * holds its own column at the DEFAULT warehouse — that is where posting a
     * document would put it (StockAllocator::split).
     */
    protected function held(int $productId, int $warehouseId): int
    {
        $at = $this->allocator->qtyAt($warehouseId, $productId);

        if ($at !== null) {
            return $at;
        }

        $default = (int) DB::table('m_warehouses')->where('is_default', true)->value('id');

        return $default === $warehouseId ? (int) DB::table('m_catalog_products')->where('id', $productId)->value('stock') : 0;
    }

    protected function receive(Document $document, $line): void
    {
        // Frozen in the books at today's rate: the delivery was paid for once.
        if ($line->unit_cost_original !== null) {
            $currency = strtoupper((string) ($line->currency ?: shop_currency()));
            $rate = $currency === shop_currency() ? 1.0 : (float) (app(Currencies::class)->find($currency)?->rate ?: 1);
            $line->forceFill([
                'currency' => $currency,
                'rate' => $rate,
                'unit_cost' => (int) round($line->unit_cost_original / $rate),
            ])->save();
        }

        $this->allocator->move($line->product_id, $document->warehouse_id, $line->qty);
    }

    protected function transfer(Document $document, $line): void
    {
        $this->allocator->move($line->product_id, $document->warehouse_id, -$line->qty);
        $this->allocator->move($line->product_id, (int) $document->to_warehouse_id, $line->qty);
    }

    protected function count(Document $document, $line): void
    {
        $line->forceFill(['expected' => $this->held($line->product_id, $document->warehouse_id)])->save();

        $this->allocator->set($line->product_id, $document->warehouse_id, max(0, $line->qty));
    }
}
