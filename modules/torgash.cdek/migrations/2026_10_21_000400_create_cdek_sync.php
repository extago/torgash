<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each thing was last pulled from CDEK, keyed by a string: 'cities' for
 * the directory, 'points' for a whole-country pass, 'points:<cityCode>' per
 * city. A missing row means "never synced".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_cdek_sync', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->timestamp('synced_at');
            $table->jsonb('meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_cdek_sync');
    }
};
