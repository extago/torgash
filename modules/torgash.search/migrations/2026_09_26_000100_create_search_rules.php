<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Where the shop steers its own search by hand (PLAN §34.5, decision 3).
 *
 * - SYNONYMS: what customers type → what to look for. `phrases` is a list
 *   (several ways of saying one thing — "мобила", "мобилка"), `target` the
 *   words to search; `mode` says whether the typed words are searched too
 *   (`also`) or not at all (`instead`).
 * - EXCLUSIONS: "a query holding one of these words must not show THAT" —
 *   products whose name holds a word (`exclude_words`) or products of a
 *   category and everything under it (`exclude_category_id`, no FK: the
 *   catalogue's table, and a deleted category simply stops matching).
 *   Triggers and names are matched by the START of a word, so "чехл" covers
 *   чехлы, чехла, чехлов.
 * - PHRASES: a phrase the shop wants suggested while typing (`priority`
 *   orders them) and, when it is exactly what was searched, products put
 *   first in the order given (`product_ids`, a jsonb ARRAY — jsonb keeps the
 *   order of an array, never of an object's keys).
 *
 * Words are stored lower-cased, as a query is normalised (SearchQuery).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE m_search_synonyms (
                id bigserial PRIMARY KEY,
                phrases jsonb NOT NULL DEFAULT '[]'::jsonb,
                target text NOT NULL,
                mode varchar(16) NOT NULL DEFAULT 'also',
                enabled boolean NOT NULL DEFAULT true,
                created_at timestamp(0) NULL,
                updated_at timestamp(0) NULL
            )
        ");

        DB::statement("
            CREATE TABLE m_search_exclusions (
                id bigserial PRIMARY KEY,
                when_words jsonb NOT NULL DEFAULT '[]'::jsonb,
                exclude_words jsonb NOT NULL DEFAULT '[]'::jsonb,
                exclude_category_id bigint NULL,
                note text NULL,
                enabled boolean NOT NULL DEFAULT true,
                created_at timestamp(0) NULL,
                updated_at timestamp(0) NULL
            )
        ");

        DB::statement("
            CREATE TABLE m_search_phrases (
                id bigserial PRIMARY KEY,
                phrase text NOT NULL UNIQUE,
                priority integer NOT NULL DEFAULT 0,
                product_ids jsonb NOT NULL DEFAULT '[]'::jsonb,
                enabled boolean NOT NULL DEFAULT true,
                created_at timestamp(0) NULL,
                updated_at timestamp(0) NULL
            )
        ");
        DB::statement('CREATE INDEX m_search_phrases_prefix_idx ON m_search_phrases (phrase text_pattern_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS m_search_phrases');
        DB::statement('DROP TABLE IF EXISTS m_search_exclusions');
        DB::statement('DROP TABLE IF EXISTS m_search_synonyms');
    }
};
