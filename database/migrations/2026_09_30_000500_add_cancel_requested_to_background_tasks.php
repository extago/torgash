<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Stop this" for a task already running. A worker cannot be interrupted from
 * outside, so the admin leaves a note on the row and the job reads it between
 * steps (TrackedJob::progress()) and stops itself — cooperatively, which is the
 * only way a half-written import can stop at a line boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('background_tasks', function (Blueprint $table) {
            $table->timestamp('cancel_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('background_tasks', function (Blueprint $table) {
            $table->dropColumn('cancel_requested_at');
        });
    }
};
