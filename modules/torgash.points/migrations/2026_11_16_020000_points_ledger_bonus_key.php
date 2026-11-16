<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bonus the shop gives outside an order's own earning (PLAN §74, stage 3) —
 * an automation rule's "Give bonus points" — is a ledger line of its own
 * type, `bonus`, and carries the key of what gave it: a rule that runs twice
 * for one event (a retry, a worker that died after writing) gives once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_points_ledger', function (Blueprint $table) {
            $table->string('source_key', 190)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('m_points_ledger', function (Blueprint $table) {
            $table->dropUnique(['source_key']);
            $table->dropColumn('source_key');
        });
    }
};
