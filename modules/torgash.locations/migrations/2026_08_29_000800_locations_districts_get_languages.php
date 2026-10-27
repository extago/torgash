<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * A district becomes translatable, exactly like a name.
 *
 * It was one text column holding the local spelling, which was fine while the
 * only readers spoke the local language. It stops being fine the moment a shop
 * turns on a third one: the town renders as 北京 or Kyiv and the disambiguator
 * beside it stays "Броварський район", so the one line whose whole job is to
 * tell two namesakes apart is the line the reader cannot read.
 *
 * So: JSONB `{"en": "Brovary District", "uk": "Броварський район", ...}`, read
 * through t('district') like every other translated field. Packs now ship one
 * `district_<code>` column per language (tools/build-locations-osm.py).
 *
 * Existing values are moved under "und" — ISO 639-2 for "undetermined" — and
 * NOT guessed at. The old column genuinely does not record which language it
 * holds: "Броварський район" and "Броварский район" differ by one letter, and
 * a country-by-country guess would silently file Ukrainian under `ru` for
 * every shop whose pack we did not write. `und` costs nothing to read —
 * HasTranslations::t() falls through to the first available value, so every
 * locale shows what it showed yesterday — and the next import replaces it with
 * the real codes. The same word is the root locale this project's database is
 * created with (see CLAUDE.md), for the same reason: it means "a human
 * language, we are not claiming which".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE m_locations_places
            ALTER COLUMN district TYPE jsonb
            USING CASE
                WHEN district IS NULL OR btrim(district) = '' THEN NULL
                ELSE jsonb_build_object('und', district)
            END
        SQL);
    }

    /**
     * Lossy, and unavoidably so: many strings go back into a column that holds
     * one. The order below is "the language the value most likely came from
     * first" — `und` is what up() wrote, then the local spellings a pack
     * carries, and English last because it is the one a reader can least tell
     * two namesakes apart by.
     *
     * A subquery is not allowed in a USING expression ("cannot use subquery in
     * transform expression"), so this cannot be written generically over
     * whatever keys the row happens to have.
     */
    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE m_locations_places
            ALTER COLUMN district TYPE varchar(255)
            USING CASE
                WHEN district IS NULL THEN NULL
                ELSE coalesce(district->>'und', district->>'uk', district->>'ru', district->>'en')
            END
        SQL);
    }
};
