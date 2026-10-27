<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A profile's file rebuilding itself when the data it reads has changed —
 * not more often than `rebuild_every` minutes — beside (or instead of) its
 * cron `schedule`. `built_fingerprint` is the state of that data the current
 * file was built from (Torgash\Exim\Fingerprint), `build_task_id` the
 * background task building it now, so one profile never has two. The last
 * three columns describe the file the feed address hands out.
 *
 * `build_task_id` has no foreign key: finished tasks are pruned, and a
 * profile pointing at a pruned one simply is not building.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_exim_profiles', function (Blueprint $table) {
            $table->boolean('rebuild_on_change')->default(false);
            $table->unsignedSmallInteger('rebuild_every')->default(30); // minutes
            $table->string('built_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('build_task_id')->nullable();
            $table->unsignedInteger('last_rows')->nullable();
            $table->unsignedBigInteger('last_size')->nullable(); // bytes
            $table->unsignedInteger('last_duration_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_exim_profiles', function (Blueprint $table) {
            $table->dropColumn(['rebuild_on_change', 'rebuild_every', 'built_fingerprint', 'build_task_id', 'last_rows', 'last_size', 'last_duration_ms']);
        });
    }
};
