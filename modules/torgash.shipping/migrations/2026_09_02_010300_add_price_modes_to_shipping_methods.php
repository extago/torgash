<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a method arrives at a price, and what it does when it cannot.
 *
 * Two independent axes, because they combine (PLAN §12.3–12.4):
 *
 *   price_mode      free | bands | carrier | quote
 *   on_incalculable hide | bands | quote
 *
 * The second one is the whole point. "Price it by weight, but if some product
 * has no weight say the carrier will quote it" is a real shop's requirement and
 * it is exactly `price_mode = bands, on_incalculable = quote`. Without it the
 * only options are charging as if the parcel weighed nothing (silently wrong)
 * or hiding the method (the customer cannot order at all).
 *
 * Existing rows keep behaving as they did: a method naming a carrier is priced
 * by that carrier, everything else by its bands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_shipping_methods', function (Blueprint $table) {
            $table->string('price_mode', 16)->default('bands')->after('provider_config');
            $table->string('on_incalculable', 16)->default('quote')->after('price_mode');
        });

        DB::table('m_shipping_methods')->whereNotNull('provider')->update(['price_mode' => 'carrier']);
    }

    public function down(): void
    {
        Schema::table('m_shipping_methods', function (Blueprint $table) {
            $table->dropColumn(['price_mode', 'on_incalculable']);
        });
    }
};
