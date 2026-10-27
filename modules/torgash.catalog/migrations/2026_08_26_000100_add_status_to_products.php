<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Boolean `enabled` becomes a three-state `status`, matching what every real
 * store admin offers (active / hidden / disabled):
 *
 *   active   — listed everywhere and reachable
 *   hidden   — NOT in listings, search, related blocks or the sitemap, but a
 *              direct URL still works. This is the state the boolean could
 *              never express, and it is the one merchants actually need: a
 *              product parked out of the catalogue while a link to it stays
 *              live for support/ads.
 *   disabled — not listed and not reachable (404), i.e. the old `enabled=false`
 *
 * Backfilled so nothing changes meaning on upgrade: true → active,
 * false → disabled. Nothing lands in `hidden` until someone picks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->index();
        });

        DB::table('m_catalog_products')->where('enabled', false)->update(['status' => 'disabled']);

        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->boolean('enabled')->default(true);
        });

        // 'hidden' has no boolean equivalent; it was visible by direct link, so
        // it rolls back to enabled rather than silently 404-ing.
        DB::table('m_catalog_products')->where('status', 'disabled')->update(['enabled' => false]);

        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
