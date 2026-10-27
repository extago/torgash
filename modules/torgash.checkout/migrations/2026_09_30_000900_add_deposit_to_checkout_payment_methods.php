<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part of an order paid online now, the rest on delivery (PLAN §40.10/1) —
 * "200 now and the rest to the courier", a shop's answer to parcels nobody
 * collects.
 *
 * On the METHOD, because it is a property of how the customer pays: `fixed`
 * (minor units of the books) or `percent` of the order. On the ORDER, frozen at
 * placement, because the Pay link in tomorrow's email must ask for what the
 * customer agreed to, not for what the method says after somebody edited it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_payment_methods', function (Blueprint $table) {
            $table->string('deposit_type', 8)->nullable();
            $table->unsignedBigInteger('deposit_value')->nullable();
        });

        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('deposit_amount')->nullable()->after('prepaid_total');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn('deposit_amount');
        });

        Schema::table('m_checkout_payment_methods', function (Blueprint $table) {
            $table->dropColumn(['deposit_type', 'deposit_value']);
        });
    }
};
