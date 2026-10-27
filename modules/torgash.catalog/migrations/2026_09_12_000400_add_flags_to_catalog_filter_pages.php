<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Laptops in stock" as an address, not only as a tick box.
 *
 * A published page is a category plus a set of characteristic values (§30.8)
 * plus, since §30.9, a town. This adds the third axis the catalogue already
 * had everywhere else: the yes/no filters — `in_stock`, `on_sale` and whatever
 * a module registers through `catalog.filters.flags` (§104).
 *
 * KEYS, not a column per filter, and that is the whole point: the registry is
 * open, so a warehouses module registering "here today" can be published as a
 * page the same afternoon without a migration. The cost is that a key may stop
 * being answerable when its module goes — and the honest answer to that is the
 * one the storefront already gives for an unknown value code: it matches
 * NOTHING, so the page stands, says what it is and shows an empty grid, rather
 * than quietly widening into the whole category.
 *
 * The fingerprint appends them only when there are any, so every row written
 * before this hashes exactly as it did — the same care the town needed, and for
 * the same reason: the column is UNIQUE and live rows are not rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_filter_pages', function (Blueprint $table) {
            $table->jsonb('flags')->nullable()->after('values');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_filter_pages', function (Blueprint $table) {
            $table->dropColumn('flags');
        });
    }
};
