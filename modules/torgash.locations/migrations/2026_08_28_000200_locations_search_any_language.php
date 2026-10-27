<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes the search column language-agnostic.
 *
 * The first version listed the keys it searched — name->>'en', name->>'local',
 * name->>'ru' — which meant a pack shipping a fourth language was silently
 * unsearchable in it, and it hardcoded the "local" key that turned out to be
 * the wrong idea entirely (a shop asking for `uk` found nothing). Now every
 * value in the JSONB is searched, whatever its language.
 *
 * A generated column cannot contain a subquery, so the aggregation lives in an
 * IMMUTABLE function — which is also the honest declaration: given the same
 * row it always returns the same text.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION m_locations_search_text(names jsonb, aliases text)
            RETURNS text
            LANGUAGE sql
            IMMUTABLE
            PARALLEL SAFE
            AS $$
                SELECT lower(
                    coalesce((SELECT string_agg(value, ' ') FROM jsonb_each_text(names)), '')
                    || ' ' || coalesce(replace(aliases, '|', ' '), '')
                )
            $$
        SQL);

        DB::statement('ALTER TABLE m_locations_places DROP COLUMN IF EXISTS search_text');

        DB::statement(<<<'SQL'
            ALTER TABLE m_locations_places
            ADD COLUMN search_text text
            GENERATED ALWAYS AS (m_locations_search_text(name, aliases)) STORED
        SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS m_locations_places_search_trgm ON m_locations_places USING gin (search_text gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE m_locations_places DROP COLUMN IF EXISTS search_text');
        DB::statement('DROP FUNCTION IF EXISTS m_locations_search_text(jsonb, text)');
    }
};
