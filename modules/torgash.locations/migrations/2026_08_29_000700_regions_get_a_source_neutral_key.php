<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regions get the same source-neutral key the places just got, and for a
 * sharper reason: their CODE is not an identity.
 *
 * GeoNames numbers Ukrainian regions its own way, ISO 3166-2 numbers them
 * another, and the two overlap — Dnipropetrovsk is "12" to ISO and "04" to
 * GeoNames, while "12" is what GeoNames calls Kyiv City. Renumbering by code
 * therefore walks into another region's row: the first attempt renamed
 * Zaporizhzhia into Ivano-Frankivsk and left Dnipropetrovsk unrenumbered.
 *
 * With a ref ("geonames:709929", "osm:UA12000000000090473") the row is found
 * by what it IS, and the code is just an attribute that can change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_locations_states', function (Blueprint $table) {
            $table->string('ref', 64)->nullable()->after('id');
            $table->unique(['country', 'ref']);
        });

        DB::statement("update m_locations_states
                          set ref = 'geonames:' || geonameid
                        where geonameid is not null and source = 'import'");
    }

    public function down(): void
    {
        Schema::table('m_locations_states', function (Blueprint $table) {
            $table->dropUnique(['country', 'ref']);
            $table->dropColumn('ref');
        });
    }
};
