<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The background task an entry was written by — a renumbering, an import —
 * so its rows read as one act of the person who started it, can be listed
 * together, and one day put back together. No foreign key: the tasks list
 * is pruned, the trail outlives it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_guard_audit', function (Blueprint $table) {
            $table->unsignedBigInteger('task_id')->nullable()->after('user_name');
            $table->string('task_name')->nullable()->after('task_id');
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::table('m_guard_audit', function (Blueprint $table) {
            $table->dropIndex(['task_id']);
            $table->dropColumn(['task_id', 'task_name']);
        });
    }
};
