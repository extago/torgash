<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A closed assortment (PLAN §46): who may see a product or a category —
 * `guest`, `registered`, `group:<id>` — as a list; empty or NULL is
 * everybody, which every row stays until the shop says otherwise. A jsonb
 * array rather than a pivot: it is read beside the row on every listing and
 * asked with one `jsonb_exists_any`, and a group deleted later simply stops
 * matching anybody (Support\Assortment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->jsonb('customer_groups')->nullable();
        });

        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->jsonb('customer_groups')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', fn (Blueprint $table) => $table->dropColumn('customer_groups'));
        Schema::table('m_catalog_categories', fn (Blueprint $table) => $table->dropColumn('customer_groups'));
    }
};
