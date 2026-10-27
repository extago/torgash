<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * place_id ↔ Nova Poshta settlement. This is the whole point of the module
 * living apart from the registry: install Nova Poshta and the mapping appears,
 * uninstall it and the mapping goes, and torgash.locations is never touched.
 *
 * A place with no row here is simply not offered Nova Poshta — the same
 * behaviour as "hide when city not found" in the carrier's own plug-ins, without a
 * column bolted onto someone else's table.
 *
 * `settlement_ref` is used for warehouse/locker delivery; `city_ref` for
 * courier (a village's parcels are dispatched from its raion centre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_np_place_map', function (Blueprint $table) {
            $table->foreignId('place_id')->primary()
                ->constrained('m_locations_places')->cascadeOnDelete();

            $table->char('settlement_ref', 36);
            $table->char('city_ref', 36)->nullable();

            // name_area_district | name_area | coords | manual
            $table->string('matched_by', 24)->default('manual');
            $table->unsignedSmallInteger('confidence')->default(0);
            $table->timestamp('checked_at')->nullable();

            $table->index('settlement_ref');
            $table->index('matched_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_np_place_map');
    }
};
