<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Which places are near this one" became a question the shop asks.
 *
 * A carrier with no branch in the customer's village now offers the nearest
 * towns that have one (`Torgash\NovaPoshta\Shipping\NearestTowns`), which is a
 * bounding box over this table — 174 000 rows of it in a Ukrainian shop, and
 * a quarter of a second without this index, inside a checkout quote.
 *
 * The index lives here rather than in the module that asked for it, because
 * the table is this one's: a module never adds columns or indexes to another
 * module's table, and "somebody looks up places by coordinate" is a fact about
 * places.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_locations_places', function (Blueprint $table) {
            $table->index(['latitude', 'longitude'], 'm_locations_places_coords_index');
        });
    }

    public function down(): void
    {
        Schema::table('m_locations_places', function (Blueprint $table) {
            $table->dropIndex('m_locations_places_coords_index');
        });
    }
};
