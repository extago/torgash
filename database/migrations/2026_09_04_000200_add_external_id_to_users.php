<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The counterparty's id in the system the shop exchanges with.
 *
 * The fourth of the entities an exchange moves (products, categories, orders,
 * customers) and the only one whose table is core's. Email is the natural key
 * a shop thinks in and it is not stable enough to be a foreign one: people
 * change it, and 1C happily holds two contacts sharing an address.
 *
 * Nullable and unique, as everywhere else — see the catalogue's migration of
 * the same date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('external_id');
        });
    }
};
