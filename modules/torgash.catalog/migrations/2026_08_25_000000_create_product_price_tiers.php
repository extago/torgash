<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wholesale / tiered pricing, the usual model: the price is the MIN price
 * among all rows where lower_limit <= qty and usergroup_id is either the
 * customer's group or the "all groups" sentinel — the cheapest matching
 * price always wins, never the closest tier.
 *
 * Unlike older engines, a product's base `price` column is NOT duplicated into a
 * row here — they always seed a lower_limit=1/usergroup=0 row per
 * product because product_prices IS their price storage; Torgash already has
 * Product::price for that, so this table only ever holds the *extra* tiers
 * a merchant opts a product into. Product::effectivePrice() folds the
 * existing group-discount price in as an always-eligible candidate
 * alongside whatever rows exist here, so a product with no rows behaves
 * exactly as before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_catalog_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('m_catalog_products')->cascadeOnDelete();
            // Null = every customer group (what other engines write as group 0, "ALL").
            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups')->cascadeOnDelete();
            $table->unsignedInteger('min_qty')->default(1);
            $table->integer('price');
            $table->timestamps();
        });

        // A plain composite unique() can't stop duplicate (product, NULL, min_qty)
        // rows in Postgres — NULL never equals NULL — so the null group is
        // folded to 0 for uniqueness purposes only, same trick the usual
        // usergroup_id=0 sentinel sidesteps by not being NULL in the first place.
        DB::statement(
            'CREATE UNIQUE INDEX m_catalog_product_prices_unique '.
            'ON m_catalog_product_prices (product_id, min_qty, COALESCE(customer_group_id, 0))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('m_catalog_product_prices');
    }
};
