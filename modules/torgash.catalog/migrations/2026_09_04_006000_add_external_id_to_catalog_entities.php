<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the OTHER system calls this row.
 *
 * The first item of §15.3 and the only one worth doing before any API exists,
 * because it is not really about the API: an exchange keyed on our own
 * autoincrements survives exactly until the first catalogue reload. 1C, a CS-
 * Cart import, a supplier's price list — each of them has its own id for a
 * product, and without somewhere to put it, "the same product" has to be
 * guessed from the sku, which shops change.
 *
 * Nullable and UNIQUE: a shop that exchanges with nobody has nulls everywhere
 * (Postgres does not count them as duplicates), and one that does cannot end up
 * with two rows claiming the same foreign id. `m_warehouses.external_id` has
 * been exactly this since August; this is the same column on the entities an
 * exchange actually moves.
 *
 * It does NOT replace `sku` or `slug`. Those are the shop's own keys, visible
 * to people; this one belongs to a machine somewhere else, and the matching
 * order — external id first, then the natural key — is what lets a product be
 * renamed on either side without the two systems losing each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('sku');
        });

        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropColumn('external_id');
        });

        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->dropColumn('external_id');
        });
    }
};
