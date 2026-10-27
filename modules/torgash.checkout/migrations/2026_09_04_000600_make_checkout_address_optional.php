<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An order that is collected has no street to be taken to.
 *
 * NOT NULL here was true while every order was sent somewhere. With collection
 * from the shop's own points (PLAN §20.4/2) it stopped being true, and the only
 * ways to keep it would have been to store an empty string — a "given address"
 * indistinguishable from a bug — or to make the customer invent one, which puts
 * a fictional address on the order for good.
 *
 * The field is still required for every option that IS delivered: checkout asks
 * the chosen option (`needs_address`) rather than reading a shop-wide setting,
 * because the same shop both delivers and hands over the counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->string('address_line')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Orders placed for collection have none; an empty string is the only
        // value that fits the old constraint without inventing a street.
        DB::table('m_checkout_orders')->whereNull('address_line')->update(['address_line' => '']);

        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->string('address_line')->nullable(false)->change();
        });
    }
};
