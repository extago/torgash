<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order placed with a carrier keeps that carrier's snapshot — branch ref and
 * number, service type, promised date — so the order survives the carrier
 * changing its tariffs or its warehouse list afterwards.
 *
 * Nullable and shapeless on purpose: a flat-rate order has nothing to put here,
 * and each carrier freezes what it needs. What is inside is written by the
 * chosen ShippingProvider's snapshot().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->jsonb('shipping_details')->nullable()->after('shipping_method_name');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn('shipping_details');
        });
    }
};
