<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regions get the same promise places already had: one a person made or edited
 * belongs to the shop, and the next pack import leaves it alone.
 *
 * Without this the registry kept two different rules for two neighbouring
 * screens, which is the kind of inconsistency nobody reads the docs for — they
 * just find out when their region's name changes back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_locations_states', function (Blueprint $table) {
            $table->string('source', 8)->default('import');
            $table->boolean('edited')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('m_locations_states', function (Blueprint $table) {
            $table->dropColumn(['source', 'edited']);
        });
    }
};
