<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a method sends the order to the customer's address at all.
 *
 * A carrier says so per variant (ProviderOffer::needsAddress — collection from
 * our points does not). A method with no carrier could not say anything, so
 * the address was demanded from everyone — including the seeded "Pickup",
 * where the customer was made to type a street the order would never go to
 * (found on the first live shop, 2026-10-21). Now the method says it, and the
 * seeded "Pickup" says no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_shipping_methods', function (Blueprint $table) {
            $table->boolean('needs_address')->default(true);
        });

        DB::table('m_shipping_methods')
            ->where(fn ($query) => $query->whereNull('provider')->orWhere('provider', ''))
            ->whereRaw("name->>'en' = 'Pickup'")
            ->update(['needs_address' => false]);
    }

    public function down(): void
    {
        Schema::table('m_shipping_methods', function (Blueprint $table) {
            $table->dropColumn('needs_address');
        });
    }
};
