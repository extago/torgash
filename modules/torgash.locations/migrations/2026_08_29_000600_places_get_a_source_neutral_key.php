<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A place is identified by a `ref`, not by a GeoNames id.
 *
 * The registry was born from GeoNames, so the pack's key was `geonameid` and
 * the table's unique index with it. Then a better source turned up for Ukraine
 * — OpenStreetMap, with the official KATOTTG codes, a Ukrainian name for 99.6%
 * of settlements against 82%, and a postal code for 99% against sixteen rows in
 * the entire country — and it has no GeoNames ids at all.
 *
 * So the key becomes a string that says where the row came from:
 * "geonames:703448", "osm:n26150422". Existing rows keep their identity (and
 * therefore every shipping zone that points at them) because the backfill
 * writes exactly the key the old packs would produce.
 *
 * `geonameid` stays: it is still the join a GeoNames pack matches on, and the
 * OSM pack carries it too for the towns it recognised. It just stops being
 * unique — one settlement in the new pack can legitimately correspond to a
 * GeoNames row that a second, near-identical row also claimed.
 *
 * `codes` is the official identifiers of the source (KATOTTG, KOATUU here).
 * They are what a carrier's own directory will be joined against — Nova Poshta
 * publishes them — and they outlive the OSM node id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_locations_places', function (Blueprint $table) {
            $table->string('ref', 64)->nullable()->after('id');
            $table->jsonb('codes')->nullable()->after('postal_to');
        });

        // Rows that came from a pack get the key that pack would give them now;
        // rows added by hand keep a null ref, which is what makes an importer
        // leave them alone anyway.
        DB::statement("update m_locations_places
                          set ref = 'geonames:' || geonameid
                        where geonameid is not null and source = 'import'");

        Schema::table('m_locations_places', function (Blueprint $table) {
            $table->unique('ref');
        });

        // The old unique index would now reject a pack that maps two towns onto
        // one GeoNames row; the column stays indexed because matching still
        // uses it.
        // A unique index made by Laravel is backed by a CONSTRAINT, and Postgres
        // refuses to drop it as an index.
        DB::statement('alter table m_locations_places drop constraint if exists m_locations_places_geonameid_unique');
        DB::statement('drop index if exists m_locations_places_geonameid_unique');
        DB::statement('create index if not exists m_locations_places_geonameid_index
                           on m_locations_places (geonameid)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists m_locations_places_geonameid_index');
        DB::statement('create unique index if not exists m_locations_places_geonameid_unique
                           on m_locations_places (geonameid)');

        Schema::table('m_locations_places', function (Blueprint $table) {
            $table->dropUnique(['ref']);
            $table->dropColumn(['ref', 'codes']);
        });
    }
};
