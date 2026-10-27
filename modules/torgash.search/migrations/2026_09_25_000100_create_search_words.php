<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue's own dictionary — every word its products are written in,
 * which is what a typo is corrected TO (PLAN §34.5: no external speller; a
 * shop's SKUs and brands are in no general dictionary anyway).
 *
 * Two counts, because a word's standing depends on where it lives: `products`
 * counts the products that carry it in a name, SKU, brand or searched
 * characteristic — a word worth suggesting —, `mentions` those that carry it
 * only in a text (short description, description, page title) — a word that
 * is KNOWN, so it is never "corrected" into something else, but a weak thing
 * to correct towards.
 *
 * The document remembers which words it contributed (`words`, `text_words`,
 * jsonb arrays), so rewriting one product moves the counts by exactly what
 * changed, and a full rebuild recounts from the documents.
 *
 * GiST, not GIN, over the word: it answers `order by word <-> ?` — the nearest
 * words by trigrams — straight from the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('
            CREATE TABLE m_search_words (
                word text PRIMARY KEY,
                products integer NOT NULL DEFAULT 0,
                mentions integer NOT NULL DEFAULT 0
            )
        ');

        DB::statement('CREATE INDEX m_search_words_trgm_idx ON m_search_words USING GIST (word gist_trgm_ops)');
        DB::statement('CREATE INDEX m_search_words_prefix_idx ON m_search_words (word text_pattern_ops)');

        DB::statement("ALTER TABLE m_search_documents ADD COLUMN words jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement("ALTER TABLE m_search_documents ADD COLUMN text_words jsonb NOT NULL DEFAULT '[]'::jsonb");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS m_search_words');
        DB::statement('ALTER TABLE m_search_documents DROP COLUMN IF EXISTS words');
        DB::statement('ALTER TABLE m_search_documents DROP COLUMN IF EXISTS text_words');
    }
};
