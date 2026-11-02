<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "The recipient pays the carrier on receipt" (PLAN §66, stage 4) — Nova
 * Poshta "from 80": that money is the carrier's, not the shop's, so it is
 * not in the order's total, its statistics or what a bank is asked for. The
 * rate the method still has is shown as an estimate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_shipping_methods', function (Blueprint $table) {
            $table->boolean('paid_to_carrier')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('m_shipping_methods', function (Blueprint $table) {
            $table->dropColumn('paid_to_carrier');
        });
    }
};
