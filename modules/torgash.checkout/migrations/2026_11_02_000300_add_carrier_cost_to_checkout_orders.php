<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A delivery the recipient pays the carrier for (PLAN §66, stage 4). The
 * shop's money stays in `shipping_cost` — 0 for such an order, so the total,
 * the taxes, the promotions, the bank and 1C need not know of it — and what
 * the carrier will ask for is kept apart: `shipping_to_carrier` says so,
 * `shipping_carrier_cost` is the ESTIMATE (NULL when nobody could say —
 * "at the carrier's rates").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->boolean('shipping_to_carrier')->default(false)->after('shipping_cost');
            $table->unsignedBigInteger('shipping_carrier_cost')->nullable()->after('shipping_to_carrier');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_to_carrier', 'shipping_carrier_cost']);
        });
    }
};
