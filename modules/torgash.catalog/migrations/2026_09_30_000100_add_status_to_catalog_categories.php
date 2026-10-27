<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A category gets the product's three states:
 *
 *   active   — in the menus, the tree, the sitemap, and reachable
 *   hidden   — OUT of every list (menus, the tree, subcategory rows, the
 *              sitemap, search suggestions, filter-page generation), but its
 *              address still opens. The "Black Friday" category a banner
 *              points at and the navigation does not.
 *   disabled — no page at all (404): the old `enabled = false`, which is also
 *              what a TECHNICAL category is — a grouping for promotions and
 *              rules that the customer never meets.
 *
 * Backfilled so nothing changes meaning: true → active, false → disabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->index();
        });

        DB::table('m_catalog_categories')->where('enabled', false)->update(['status' => 'disabled']);

        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->boolean('enabled')->default(true);
        });

        // 'hidden' opened by its address, so it rolls back to enabled rather
        // than to a 404.
        DB::table('m_catalog_categories')->where('status', 'disabled')->update(['enabled' => false]);

        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
