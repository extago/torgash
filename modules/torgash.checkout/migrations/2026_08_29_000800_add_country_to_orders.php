<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order had a city and a postal code but no country, which was survivable
 * only while every shop served exactly one. It does not survive two enabled
 * countries: "Ivanivka" is then an ambiguous address, and the zone that priced
 * the order cannot be reconstructed from what was stored.
 *
 * Nullable, because orders placed before this column existed genuinely do not
 * know, and guessing a country for them would be inventing a fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->char('country', 2)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};
