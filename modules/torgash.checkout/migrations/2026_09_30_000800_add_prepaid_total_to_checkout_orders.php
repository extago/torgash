<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The part of what an order holds that was paid BEFORE any gateway was asked —
 * a gift card, chiefly (PLAN §40.4). It is inside `paid_total` like every
 * other payment; this column only says how much of it is stored value, which
 * is what a gateway must not ask the bank for again and what a refund gives
 * back first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('prepaid_total')->default(0)->after('paid_total');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn('prepaid_total');
        });
    }
};
