<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an order was placed — the storefront's checkout, one click, a manager
 * in the admin, the shop API, an exchange for a return (`OrderSource`).
 *
 * Written by PlaceOrder, the one door every order comes through. Older rows
 * stay NULL: nothing on them says which door it was, and a guess written
 * down now would read like a fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->string('source', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
