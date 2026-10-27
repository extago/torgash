<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches and parcel lockers, fetched per town on first need and refreshed on
 * a TTL — never imported in bulk (that is hundreds of thousands of rows and
 * changes weekly). Exactly the lazy-by-city model Nova Poshta's own module
 * uses.
 *
 * `settlement_ref` points at the m_np_settlements row (its Nova Poshta city
 * ref). `category` is derived from `type_ref` so the checkout picker can split
 * "branch" from "locker" without knowing the UUIDs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_np_warehouses', function (Blueprint $table) {
            $table->char('ref', 36)->primary();
            $table->char('settlement_ref', 36);

            $table->string('number', 12)->default('');
            $table->char('type_ref', 36)->default('');
            // branch | locker | postomat_pb | cargo
            $table->string('category', 16)->default('branch');

            $table->string('name_uk', 255)->default('');
            $table->string('name_ru', 255)->default('');
            $table->string('short_uk', 255)->default('');
            $table->string('short_ru', 255)->default('');

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->unsignedInteger('max_weight_kg')->default(0);
            $table->unsignedSmallInteger('max_width_cm')->default(0);
            $table->unsignedSmallInteger('max_height_cm')->default(0);
            $table->unsignedSmallInteger('max_length_cm')->default(0);

            $table->timestamp('synced_at')->nullable();

            $table->index('settlement_ref');
            $table->index(['settlement_ref', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_np_warehouses');
    }
};
