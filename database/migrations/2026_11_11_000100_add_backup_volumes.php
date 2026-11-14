<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A backup cut into volumes (PLAN §67.12): the list of them, each with its
 * size and checksum — what a restore compares every fetched volume with.
 * NULL is a backup kept as one file (or a folder), as every one before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->jsonb('volumes')->nullable()->after('sha256');
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropColumn('volumes');
        });
    }
};
