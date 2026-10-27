<?php

namespace Torgash\Inventory;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Stock\StockMoves;
use Torgash\Inventory\Models\Document;
use Torgash\Warehouses\StockAllocator;

/**
 * Taking a POSTED document back, as if it had never been posted — the one
 * exception to "posted = closed", for a document that should never have
 * existed (typed by mistake, posted twice, somebody's nonsense). A mistake in
 * an honest document is still the next document's to correct.
 *
 * Every movement the document made is undone in reverse: what went out comes
 * back into the very batches it was taken from, what came in leaves and its
 * batches go with it. The document's rows in the journal and their costs are
 * removed, so the journal of a product still adds up to its stock; the
 * document itself stays, status `revoked`, with who, when and why.
 *
 * Refused whenever undoing would rewrite something that happened AFTER:
 * units a document brought in that have since been sold, written off or
 * moved (an order's cost was worked out from that batch — taking it away
 * would change what that sale earned), or a warehouse that no longer holds
 * what would leave it. Every reason is named at once, line by line.
 */
class Revoking
{
    /** The cause the undoing runs under; the journal does not write it down. */
    public const CAUSE = 'revoke';

    public function __construct(protected StockAllocator $allocator, protected StockMoves $moves) {}

    /** @return list<string> why this document cannot be taken back; empty when it can */
    public function obstacles(Document $document): array
    {
        if (! $document->isPosted()) {
            return [__('Only a posted document can be revoked.')];
        }

        $reasons = [];

        foreach ($this->movesOf($document) as $move) {
            if ($move->delta <= 0) {
                continue; // what went out can always come back
            }

            $batches = DB::table('m_inventory_batches')->where('move_id', $move->id)->pluck('id');
            $since = (int) DB::table('m_inventory_move_costs')
                ->whereIn('batch_id', $batches)->where('move_id', '<>', $move->id)
                ->join('m_inventory_moves as m', 'm.id', '=', 'm_inventory_move_costs.move_id')
                ->where('m.delta', '<', 0)
                ->sum('m_inventory_move_costs.qty');

            if ($since > 0) {
                $reasons[] = __(':sku: :qty of the units it brought in have already been sold, written off or moved.', ['sku' => $move->sku, 'qty' => $since]);

                continue;
            }

            $held = (int) ($this->allocator->qtyAt((int) $move->warehouse_id, (int) $move->product_id) ?? 0);
            if ($held < $move->delta) {
                $reasons[] = __(':sku: :need would leave the warehouse, it holds :held.', ['sku' => $move->sku, 'need' => $move->delta, 'held' => $held]);
            }
        }

        return $reasons;
    }

    public function revoke(Document $document, User $by, string $reason): void
    {
        DB::transaction(function () use ($document, $by, $reason) {
            $document = Document::lockForUpdate()->findOrFail($document->id);

            if ($obstacles = $this->obstacles($document)) {
                throw ValidationException::withMessages(['revoke' => $obstacles]);
            }

            $moves = $this->movesOf($document);
            $cause = ['type' => self::CAUSE, 'document_id' => $document->id, 'number' => $document->number];

            $this->moves->because($cause, function () use ($moves) {
                // Last first: a transfer's arrival is undone before its departure.
                foreach ($moves->reverse() as $move) {
                    $this->allocator->move((int) $move->product_id, (int) $move->warehouse_id, -(int) $move->delta);
                }
            });

            $ids = $moves->pluck('id');

            foreach (DB::table('m_inventory_move_costs')->whereIn('move_id', $ids)->whereNotNull('batch_id')->get() as $part) {
                $delta = (int) $moves->firstWhere('id', $part->move_id)->delta;
                if ($delta < 0) {
                    // Back into the batch it was taken from.
                    DB::table('m_inventory_batches')->where('id', $part->batch_id)->increment('remaining', (int) $part->qty);
                }
            }

            DB::table('m_inventory_move_costs')->whereIn('move_id', $ids)->delete();
            DB::table('m_inventory_batches')->whereIn('move_id', $ids)->delete();
            DB::table('m_inventory_moves')->whereIn('id', $ids)->delete();

            $document->forceFill([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoked_by' => $by->id,
                'revoked_by_name' => $by->name,
                'revoke_reason' => mb_substr($reason, 0, 500),
            ])->save();

            hook_action('inventory.document.revoked', ['document' => $document]);
        });
    }

    protected function movesOf(Document $document)
    {
        return DB::table('m_inventory_moves')->where('document_id', $document->id)->orderBy('id')
            ->get(['id', 'product_id', 'sku', 'warehouse_id', 'delta']);
    }
}
