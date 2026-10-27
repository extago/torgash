<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A rule is no longer only about an order (Subjects\Subject), so a run says
 * WHAT it ran against: `subject` + `subject_id`. `order_id` stays, filled for
 * order runs, because older rows and anything reading them know that column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_automations_runs', function (Blueprint $table) {
            $table->string('subject', 32)->default('order')->after('rule_name');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject');
            $table->index(['subject', 'subject_id']);
        });

        DB::table('m_automations_runs')->whereNotNull('order_id')->update(['subject_id' => DB::raw('order_id')]);
    }

    public function down(): void
    {
        Schema::table('m_automations_runs', function (Blueprint $table) {
            $table->dropIndex(['subject', 'subject_id']);
            $table->dropColumn(['subject', 'subject_id']);
        });
    }
};
