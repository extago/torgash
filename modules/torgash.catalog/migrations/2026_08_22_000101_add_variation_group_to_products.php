<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product "variations", the way established engines do them: a variation
 * group links several INDEPENDENT products (each its own SKU/price/stock),
 * and the storefront renders a swatch/button selector that jumps between
 * sibling products. We replicate that model but flattened (some engines nest
 * groups two levels deep; not worth the complexity here) and without a
 * separate managed "features" catalog — the distinguishing axes are simply
 * computed by diffing each sibling's existing JSONB `features` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->string('variation_group')->nullable()->index()->after('features');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropColumn('variation_group');
        });
    }
};
