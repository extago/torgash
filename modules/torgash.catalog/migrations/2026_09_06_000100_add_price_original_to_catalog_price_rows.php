<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wholesale tiers (`m_catalog_product_prices`) and per-SKU price-type
 * overrides (`m_catalog_product_price_type_overrides`) get the same `_original`
 * twin the product's own three amounts already have.
 *
 * A tier or an override belongs to a product, and a product's cost, price and
 * RRP are one negotiation in one currency (`m_catalog_products.price_currency`)
 * — so a wholesale price for a hryvnia-priced product is a hryvnia number too.
 * There is therefore NO `price_currency` column here: the currency is the
 * product's, and `price_original` is NULL exactly when the product's is (the
 * shop's own currency, nothing to convert or remember).
 *
 * Backfill: for a row whose product IS priced in another currency, seed
 * `price_original` from the books amount at the current rate — the same
 * arithmetic `Prices::columns()` does, inverted. It can be a hair off if the
 * rate moved since that row was last saved, and it self-corrects the next time
 * the product is saved; leaving it NULL would instead mean the row silently
 * stops tracking the rate until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_product_prices', function (Blueprint $table) {
            $table->integer('price_original')->nullable()->after('price');
        });

        Schema::table('m_catalog_product_price_type_overrides', function (Blueprint $table) {
            $table->integer('price_original')->nullable()->after('price');
        });

        foreach (['m_catalog_product_prices', 'm_catalog_product_price_type_overrides'] as $table) {
            DB::statement(
                "UPDATE {$table} AS t
                 SET price_original = round(t.price * c.rate)
                 FROM m_catalog_products AS p
                 JOIN currencies AS c ON c.code = p.price_currency
                 WHERE t.product_id = p.id"
            );
        }
    }

    public function down(): void
    {
        Schema::table('m_catalog_product_prices', function (Blueprint $table) {
            $table->dropColumn('price_original');
        });

        Schema::table('m_catalog_product_price_type_overrides', function (Blueprint $table) {
            $table->dropColumn('price_original');
        });
    }
};
