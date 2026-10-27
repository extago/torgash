<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A typical engine's own search is
 * a plain word-tokenized LIKE against name/description/SKU, AND-ed across
 * query words — no fulltext index at all. We keep the same word-AND spirit
 * (proven in production) but use real Postgres full-text search: a
 * generated tsvector (name/SKU weighted 'A', description 'B') with a GIN
 * index for ranked results, plus pg_trgm as a typo-tolerant fallback when
 * the exact/prefix match finds nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement(<<<'SQL'
            ALTER TABLE m_catalog_products
            ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                setweight(to_tsvector('simple', coalesce(name->>'en', '') || ' ' || coalesce(name->>'ru', '')), 'A') ||
                setweight(to_tsvector('simple', coalesce(sku, '')), 'A') ||
                setweight(to_tsvector('simple', coalesce(description->>'en', '') || ' ' || coalesce(description->>'ru', '')), 'B')
            ) STORED
        SQL);

        DB::statement('CREATE INDEX m_catalog_products_search_vector_idx ON m_catalog_products USING GIN (search_vector)');
        DB::statement("CREATE INDEX m_catalog_products_name_trgm_idx ON m_catalog_products USING GIN ((coalesce(name->>'en', '') || ' ' || coalesce(name->>'ru', '')) gin_trgm_ops)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS m_catalog_products_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS m_catalog_products_search_vector_idx');
        DB::statement('ALTER TABLE m_catalog_products DROP COLUMN IF EXISTS search_vector');
    }
};
