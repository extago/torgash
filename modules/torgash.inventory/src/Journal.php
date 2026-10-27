<?php

namespace Torgash\Inventory;

use Illuminate\Support\Facades\DB;

/**
 * Writes `catalog.stock.moved` down (PLAN §42) — the one listener, so every
 * movement the engine announces is one row, whoever caused it.
 *
 * The cause's own columns (`type`, `order_id`, `document_id`) are lifted out
 * for filtering; the rest of what the cause said stays in `ref`. A plain
 * insert, not a model: this runs inside every stock change, and the audit
 * would otherwise record the journal as an edit of the journal.
 */
class Journal
{
    /** @param  array<string, mixed>  $move  as announced */
    public function write(array $move): void
    {
        $cause = (array) ($move['cause'] ?? []);

        // Taking a document back erases its own rows (`Revoking`); writing the
        // undoing down would leave the very movements it is removing.
        if (($cause['type'] ?? null) === Revoking::CAUSE) {
            return;
        }

        $ref = array_diff_key($cause, array_flip(['type', 'order_id', 'document_id']));

        $id = DB::table('m_inventory_moves')->insertGetId([
            'product_id' => (int) $move['product_id'],
            'sku' => DB::table('m_catalog_products')->where('id', (int) $move['product_id'])->value('sku'),
            'warehouse_id' => $move['warehouse_id'] ?? null,
            'delta' => (int) $move['delta'],
            'after' => $move['after'] ?? null,
            'type' => substr((string) ($cause['type'] ?? 'edit'), 0, 16),
            'order_id' => $cause['order_id'] ?? null,
            'document_id' => $cause['document_id'] ?? null,
            'ref' => $ref === [] ? null : json_encode($ref, JSON_UNESCAPED_UNICODE),
            'user_id' => $move['user_id'] ?? null,
            'user_name' => $move['user_name'] ?? null,
            'created_at' => now(),
        ]);

        // What it cost, from the batches, the moment it is written down.
        app(Batches::class)->apply($id, $move);
    }
}
