<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discounts after the coupon that are not the manager's — bonus points first
 * (PLAN §40.5): `[{code, label, amount, …}]`, frozen at placement as money,
 * because what a customer spent points on is a fact about the past and not a
 * formula to re-run when the order is edited. They are inside
 * `discount_amount` like the coupon and the manager's own, which is what makes
 * them lower the tax base and reach 1C as a discount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->jsonb('other_discounts')->nullable()->after('manual_discount');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn('other_discounts');
        });
    }
};
