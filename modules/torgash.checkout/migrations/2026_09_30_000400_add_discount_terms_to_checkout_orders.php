<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an order's discount is MADE OF, so an edit can recompute it.
 *
 * `discount_amount` stays the one number everything adds up (totals, 1C, the
 * API, the invoice); these say where it comes from. `coupon_terms` is the
 * coupon as it was when applied — type, value, minimum, and how much of the
 * subtotal the coupon did not reach (a kit's goods, see OrderDiscounts) — so
 * ten percent stays ten percent of what the order now holds, not a frozen sum.
 * `manual_discount` is the manager's own: type, value, reason, who, and the
 * amount it came to. Orders placed before this have neither, and keep the sum
 * they were placed with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->jsonb('coupon_terms')->nullable();
            $table->jsonb('manual_discount')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn(['coupon_terms', 'manual_discount']);
        });
    }
};
