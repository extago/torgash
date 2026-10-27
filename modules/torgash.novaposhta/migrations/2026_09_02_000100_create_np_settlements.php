<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nova Poshta's own settlement directory — the carrier's list, not ours.
 *
 * We keep it in full (~30 000 rows) so a one-time reconciliation against
 * torgash.locations can run offline: match on name + oblast + raion, break ties
 * by coordinates. The result lands in m_np_place_map; this table is the carrier
 * side of that join and nothing else reads it at checkout.
 *
 * `ref` is Nova Poshta's UUID. `city_ref` is the "city" a settlement is
 * attached to for door delivery (a village ships from its raion centre) —
 * Nova Poshta's own settlement reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_np_settlements', function (Blueprint $table) {
            $table->char('ref', 36)->primary();

            // 'settlement' — from getSettlements (~30k, may have no warehouse)
            // 'city'       — from getCities (~1.5k, has its own warehouses)
            $table->string('kind', 12)->default('settlement');

            $table->string('name_uk', 190)->default('');
            $table->string('name_ru', 190)->default('');
            $table->string('area', 120)->default('');
            $table->string('region', 120)->nullable();
            $table->string('settlement_type', 60)->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->boolean('has_warehouse')->default(false);
            $table->char('city_ref', 36)->nullable();

            $table->string('index1', 12)->nullable();
            $table->string('index2', 12)->nullable();

            $table->timestamp('synced_at')->nullable();

            $table->index('kind');
            $table->index('area');
            // Matching filters by oblast then compares names; a lowercased
            // functional index is added with the matcher.
            $table->index('name_uk');
            $table->index('name_ru');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_np_settlements');
    }
};
