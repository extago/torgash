<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each thing was last pulled from Nova Poshta, keyed by a string so one
 * table covers all of it: 'settlements' for the whole directory,
 * 'warehouses:<settlementRef>' per town, 'handbook:<name>' for reference lists.
 *
 * This is the directory's sync time plus the per-city update stamps, in one
 * place. A missing row means "never synced".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_np_sync', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->timestamp('synced_at');
            $table->jsonb('meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_np_sync');
    }
};
