<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What a product has cost, so a discount can be measured against a price the
 * product really had (PLAN §36 — the EU's Omnibus rule: beside a reduction,
 * the lowest price of the 30 days BEFORE it).
 *
 * INTERVALS of the public price, not daily snapshots: a price that did not
 * move for a year is one row, and "what was the lowest in these 30 days" is
 * one MIN over the rows that overlap them. The open row (`ends_at` NULL) is
 * the price now — exactly one per product, which a partial unique index says.
 *
 * `prior_lowest_price` on the product is that answer, DERIVED (recomputed each
 * time the history moves) and kept as a column so a card reads a column and
 * the "on sale" filter can ask SQL. NULL means "no history before the current
 * price", and then the shop claims nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE TABLE m_catalog_price_history (
                id bigserial PRIMARY KEY,
                product_id bigint NOT NULL REFERENCES m_catalog_products(id) ON DELETE CASCADE,
                price bigint NOT NULL,
                starts_at timestamp(0) NOT NULL,
                ends_at timestamp(0) NULL
            )
        ');
        DB::statement('CREATE INDEX m_catalog_price_history_product_idx ON m_catalog_price_history (product_id, starts_at)');
        DB::statement('CREATE UNIQUE INDEX m_catalog_price_history_open_idx ON m_catalog_price_history (product_id) WHERE ends_at IS NULL');
        DB::statement('ALTER TABLE m_catalog_products ADD COLUMN prior_lowest_price bigint NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE m_catalog_products DROP COLUMN IF EXISTS prior_lowest_price');
        DB::statement('DROP TABLE IF EXISTS m_catalog_price_history');
    }
};
