<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bonus points, stage 4 (PLAN §74).
 *
 * - `m_points_ledger.expiry_warned_at` — when the customer was written that
 *   these points are about to expire: a credit is warned about once.
 * - `m_points_payment_methods` — points a way of paying adds to an order:
 *   `fixed` (whole points) or `percent` (of the goods after every discount,
 *   as points). A module may not put a column on checkout's table, and
 *   uninstalling must take the numbers with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_points_ledger', function (Blueprint $table) {
            $table->timestamp('expiry_warned_at')->nullable();
            $table->index(['expires_at', 'user_id']);
        });

        Schema::create('m_points_payment_methods', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_method_id')->primary();
            // fixed | percent
            $table->string('mode', 16);
            $table->decimal('value', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('m_points_payment_methods');

        Schema::table('m_points_ledger', function (Blueprint $table) {
            $table->dropIndex(['expires_at', 'user_id']);
            $table->dropColumn('expiry_warned_at');
        });
    }
};
