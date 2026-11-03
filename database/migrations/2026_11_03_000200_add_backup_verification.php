<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A backup checked by restoring it (PLAN §67.6): when, whether it passed,
 * and in words what did not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable();
            $table->string('verify_status', 16)->nullable();
            $table->text('verify_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropColumn(['verified_at', 'verify_status', 'verify_error']);
        });
    }
};
