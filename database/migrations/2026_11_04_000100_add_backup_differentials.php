<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A backup of only the files changed since the last full one (PLAN §67.10):
 * the run remembers the full backup it leans on and the moment it took the
 * files from. A base that is deleted leaves the column null — the journal
 * then says, in words, that the differential has nothing to lean on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->foreignId('base_run_id')->nullable()->after('contents')->constrained('backup_runs')->nullOnDelete();
            $table->timestamp('files_since')->nullable()->after('base_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('base_run_id');
            $table->dropColumn('files_since');
        });
    }
};
