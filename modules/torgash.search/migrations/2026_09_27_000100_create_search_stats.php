<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What customers search for, counted (PLAN §34, step 5).
 *
 * `m_search_stats` is ONE ROW PER QUERY PER DAY — the query as the engine
 * normalises it, never a person: no user, no session, no address. A row per
 * search would be a diary of what each visitor typed, which the shop would
 * then have to answer for (the reason the visitor's own history lives in
 * their session); a daily count answers every question the screen asks —
 * the most searched, what finds nothing, what gets clicked — over any
 * period, and it is small, which a table growing with every keystroke is not.
 * `found` is what the last search of that day found; `zero` counts the
 * searches that found nothing, so a query that finds nothing only sometimes
 * (stock ran out) is visible as such.
 *
 * `m_search_clicks` is which product was opened from which query, counted —
 * what lifts a product for THAT query ("чехол" → the case people actually
 * choose), and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE TABLE m_search_stats (
                query text NOT NULL,
                day date NOT NULL,
                searches integer NOT NULL DEFAULT 0,
                zero integer NOT NULL DEFAULT 0,
                found integer NOT NULL DEFAULT 0,
                clicks integer NOT NULL DEFAULT 0,
                PRIMARY KEY (query, day)
            )
        ');
        DB::statement('CREATE INDEX m_search_stats_day_idx ON m_search_stats (day)');
        DB::statement('CREATE INDEX m_search_stats_prefix_idx ON m_search_stats (query text_pattern_ops)');

        DB::statement('
            CREATE TABLE m_search_clicks (
                query text NOT NULL,
                product_id bigint NOT NULL REFERENCES m_catalog_products(id) ON DELETE CASCADE,
                clicks integer NOT NULL DEFAULT 0,
                last_at timestamp(0) NULL,
                PRIMARY KEY (query, product_id)
            )
        ');
        DB::statement('CREATE INDEX m_search_clicks_product_idx ON m_search_clicks (product_id)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS m_search_clicks');
        DB::statement('DROP TABLE IF EXISTS m_search_stats');
    }
};
