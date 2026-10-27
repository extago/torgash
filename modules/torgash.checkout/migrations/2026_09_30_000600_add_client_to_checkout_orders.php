<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What else was known about the buyer's browser when the order was placed —
 * the browser and device, the languages it asked for, and whatever a module
 * adds (Orders\ClientFacts). A bag rather than a column per fact, because the
 * list is the shop's choice and a module's to extend; the address stays a
 * column of its own, since it is searched and counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->jsonb('client')->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn('client');
        });
    }
};
