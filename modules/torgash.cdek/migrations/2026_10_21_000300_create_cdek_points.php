<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pickup points and parcel lockers, fetched per city on first need (or the
 * whole country at once from the data screen) and refreshed on a TTL.
 *
 * `code` is CDEK's own ("NSK23") — what an order names as its
 * `delivery_point`. Weights are the carrier's, in kilograms with decimals;
 * `dimensions` is a locker's list of cells, which is the only size limit the
 * carrier publishes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_cdek_points', function (Blueprint $table) {
            $table->string('code', 32)->primary();
            $table->unsignedBigInteger('city_code');

            // PVZ | POSTAMAT
            $table->string('type', 16)->default('PVZ');
            $table->string('owner', 24)->nullable();

            $table->string('name', 255)->default('');
            $table->string('address', 255)->default('');
            $table->string('address_full', 500)->default('');
            $table->string('address_comment', 1000)->nullable();
            $table->string('postal_code', 12)->nullable();
            $table->string('nearest_metro', 120)->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->decimal('weight_min', 8, 2)->default(0);
            $table->decimal('weight_max', 8, 2)->default(0);
            // [{"width": 10, "height": 10, "depth": 10}, ...]
            $table->jsonb('dimensions')->nullable();

            $table->string('work_time', 255)->nullable();
            // [{"day": 1, "time": "10:00/20:00"}, ...]
            $table->jsonb('schedule')->nullable();
            $table->string('phone', 32)->nullable();

            $table->boolean('is_handout')->default(true);
            $table->boolean('allowed_cod')->default(false);
            $table->boolean('have_cashless')->default(false);
            $table->boolean('is_dressing_room')->default(false);

            $table->timestamp('synced_at')->nullable();

            $table->index('city_code');
            $table->index(['city_code', 'type']);
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_cdek_points');
    }
};
