<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Countries become editable, which mainly means translatable: a shop that adds
 * a language has 252 country names nobody has written yet, and the pack only
 * carries the languages it was built with. Filling them in by hand must survive
 * the next import of the world list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_locations_countries', function (Blueprint $table) {
            $table->string('source', 8)->default('import');
            $table->boolean('edited')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('m_locations_countries', function (Blueprint $table) {
            $table->dropColumn(['source', 'edited']);
        });
    }
};
