<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment method's surcharge (PLAN §66) — "a company pays by invoice, plus
 * 10%", a percent and a fixed amount at once, measured against
 * the goods after every discount, with or without the delivery.
 *
 * On the METHOD: `surcharge_percent` in hundredths of a percent (250 = 2.5%),
 * `surcharge_fixed` in minor units of the books, `surcharge_base` (goods |
 * goods_shipping), `surcharge_title` (translatable — what the line is called)
 * `surcharge_display` (what the list of methods says beside the name) and
 * `surcharge_in_totals` (a line of its own in the bill, or inside the total
 * unnamed) — two places, two answers: a shop may keep the list quiet and
 * name the line, or the other way round.
 *
 * On the ORDER, apart from its goods like `shipping_cost`: the amount, and the
 * TERMS it came from, so an edit of the order's contents recomputes it the way
 * a coupon's terms are recomputed — and a later edit of the method never
 * reaches a placed order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_payment_methods', function (Blueprint $table) {
            $table->unsignedInteger('surcharge_percent')->default(0);
            $table->unsignedBigInteger('surcharge_fixed')->default(0);
            $table->string('surcharge_base', 16)->default('goods');
            $table->jsonb('surcharge_title')->nullable();
            $table->string('surcharge_display', 8)->default('percent');
            $table->string('surcharge_in_totals', 8)->default('line');
        });

        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_surcharge')->default(0)->after('shipping_cost');
            $table->jsonb('payment_surcharge_terms')->nullable()->after('payment_surcharge');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn(['payment_surcharge', 'payment_surcharge_terms']);
        });

        Schema::table('m_checkout_payment_methods', function (Blueprint $table) {
            $table->dropColumn(['surcharge_percent', 'surcharge_fixed', 'surcharge_base', 'surcharge_title', 'surcharge_display', 'surcharge_in_totals']);
        });
    }
};
