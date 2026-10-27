<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One searchable document per product, in this module's own table.
 *
 * Not the catalogue's generated `search_vector` column: a generated column can
 * only read its own row, and what a customer types is also the product's
 * BRAND, its characteristics and its name in every language the shop speaks —
 * rows of other tables. So the document is built in PHP (`Indexer`) and
 * written here, rebuilt when a product is saved.
 *
 * `document` holds each field twice — as written (for the start of a word:
 * "ноут" → "ноутбук") and reduced to its stem in the language it is written
 * in ("ноутбуки" → "ноутбук") — with the field's weight. `title` and `sku` are
 * kept plain for what a tsvector cannot answer: "the name begins with what
 * was typed", "this is exactly the SKU", and the trigram typo fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('
            CREATE TABLE m_search_documents (
                product_id bigint PRIMARY KEY REFERENCES m_catalog_products(id) ON DELETE CASCADE,
                document tsvector NOT NULL,
                title text NOT NULL DEFAULT \'\',
                sku text NOT NULL DEFAULT \'\',
                updated_at timestamp(0) NULL
            )
        ');

        DB::statement('CREATE INDEX m_search_documents_document_idx ON m_search_documents USING GIN (document)');
        DB::statement('CREATE INDEX m_search_documents_title_trgm_idx ON m_search_documents USING GIN (title gin_trgm_ops)');
        DB::statement('CREATE INDEX m_search_documents_sku_idx ON m_search_documents (sku text_pattern_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS m_search_documents');
    }
};
