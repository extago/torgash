<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CDEK's own city directory — the carrier's list, not ours.
 *
 * Kept in full (~140 000 rows for Russia, measured) so the one-time
 * reconciliation against torgash.locations can run offline, and so the admin's
 * "bind by hand" picker and the sender picker search a table rather than the
 * API. Nothing reads it at checkout: that goes through m_cdek_place_map.
 *
 * `code` is CDEK's integer city code — what every other call of its API takes
 * (`city_code`, `from_location.code`). Unlike Nova Poshta there is one list,
 * not two: the same code is addressed for a pickup point and for a courier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_cdek_cities', function (Blueprint $table) {
            $table->unsignedBigInteger('code')->primary();
            $table->uuid('uuid')->nullable();
            $table->char('country', 2);

            $table->string('name', 190)->default('');
            $table->string('region', 190)->default('');
            $table->unsignedInteger('region_code')->nullable();
            $table->string('sub_region', 190)->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('time_zone', 40)->nullable();

            $table->timestamp('synced_at')->nullable();

            $table->index('country');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_cdek_cities');
    }
};
