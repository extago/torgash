<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The address an order was placed FROM (older engines keep the same, as
 * `ip_address`). What fraud looks like is five orders from one address under
 * five names, and that question cannot be asked afterwards of a row that never
 * wrote the address down. 45 characters is the longest spelling of an IPv6
 * address; NULL is an order nobody placed from a browser — a manager's
 * exchange, an import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('customer_ref')->index();
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn('ip_address');
        });
    }
};
