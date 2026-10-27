<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Budget laptops" as an address (PLAN §30.14) — the fourth thing a published
 * page can stand for, after values, flags and a town.
 *
 * Minor units, the shop's own books, exactly like every other money column —
 * never the customer's typed currency, or the currency switcher would multiply
 * published addresses. NULL is an open boundary ("up to" / "from"), and the
 * pair is appended to the fingerprint only when either is set, the same trick
 * `flags` and `place_id` already use, so a row written before this hashes
 * exactly as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_filter_pages', function (Blueprint $table) {
            $table->unsignedBigInteger('price_min')->nullable()->after('flags');
            $table->unsignedBigInteger('price_max')->nullable()->after('price_min');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_filter_pages', function (Blueprint $table) {
            $table->dropColumn(['price_min', 'price_max']);
        });
    }
};
