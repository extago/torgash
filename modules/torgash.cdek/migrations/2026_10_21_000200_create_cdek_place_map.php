<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * place_id ↔ CDEK city code. Install CDEK and the mapping appears, uninstall it
 * and the mapping goes; torgash.locations is never touched.
 *
 * A place with no row here is simply not offered CDEK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_cdek_place_map', function (Blueprint $table) {
            $table->foreignId('place_id')->primary()
                ->constrained('m_locations_places')->cascadeOnDelete();

            $table->unsignedBigInteger('city_code');

            // coords | name_region | manual
            $table->string('matched_by', 24)->default('manual');
            $table->unsignedSmallInteger('confidence')->default(0);
            $table->timestamp('checked_at')->nullable();

            $table->index('city_code');
            $table->index('matched_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_cdek_place_map');
    }
};
