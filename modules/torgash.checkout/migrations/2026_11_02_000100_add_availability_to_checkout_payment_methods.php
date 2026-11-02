<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a payment method is offered to (PLAN §66, stage 3) — "an invoice for a
 * company", "cash only with our own courier", "deferred payment for the
 * wholesale group". All three are the method's own answers,
 * the way a checkout field names its buyer and its deliveries.
 *
 * `buyers`: all | person | company. `customer_groups`: the catalogue's
 * vocabulary (guest, registered, group:<id>), null = everybody.
 * `shipping_methods`: delivery method ids, null = with any delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_payment_methods', function (Blueprint $table) {
            $table->string('buyers', 8)->default('all');
            $table->jsonb('customer_groups')->nullable();
            $table->jsonb('shipping_methods')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_payment_methods', function (Blueprint $table) {
            $table->dropColumn(['buyers', 'customer_groups', 'shipping_methods']);
        });
    }
};
