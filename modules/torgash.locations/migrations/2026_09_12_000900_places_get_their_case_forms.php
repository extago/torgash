<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The town's name in the case a sentence needs it in.
 *
 * `{"ru": {"6": "Киеве"}, "uk": {"2": "Києва"}}` — per language, keyed by case
 * number, and holding ONLY what a person corrected. Everything else is derived
 * by {@see Torgash\Locations\Declension} at read time, which is why this column
 * is nullable and stays empty on a freshly imported pack of 192 000 rows.
 *
 * Not seven columns as the addon this was read from does: English has one form,
 * Russian six, Ukrainian seven, and a shop may add a language nobody here has
 * heard of. jsonb says all of that; a column per case says one language's
 * grammar is the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_locations_places', function (Blueprint $table) {
            $table->jsonb('forms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_locations_places', function (Blueprint $table) {
            $table->dropColumn('forms');
        });
    }
};
