<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product priced in a currency that is not the shop's own.
 *
 * A Ukrainian shop keeping books in dollars still has goods whose price is a
 * hryvnia number — it was agreed with the supplier in hryvnia and must not
 * drift by a kopeck every time the rate moves. The opposite product, imported
 * and priced in dollars, must not drift either. Both live in one catalogue.
 *
 * The shape follows from that. `<field>_original` is the SOURCE OF TRUTH — the
 * number the shop typed, in `price_currency` — and the existing `price`,
 * `cost_price` and `list_price` stay exactly what they have always been: the
 * shop's books, in minor units, which is what every query, every sort, every
 * filter, every module and every report already reads. They become DERIVED:
 * recomputed on save and again whenever that currency's rate changes
 * (Torgash\Catalog\Pricing\Prices::rebase, driven by core.currency.rate_changed).
 *
 * Storing only the original and converting on read was the alternative and it
 * is worse in every direction: `order by price` and `where price between` stop
 * working in SQL, and every module that has ever read `->price` would have to
 * learn about currencies. Storing only the books amount was what we had, and it
 * is the thing the shop owner complained about — the hryvnia price moves.
 *
 * NULL `price_currency` means "the shop's own", which is every product that
 * exists today; the `_original` columns are then NULL too and nothing is
 * derived from anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->string('price_currency', 3)->nullable()->after('price');
            $table->bigInteger('price_original')->nullable()->after('price_currency');
            $table->bigInteger('cost_price_original')->nullable()->after('cost_price');
            $table->bigInteger('list_price_original')->nullable()->after('list_price');

            // Every rate change sweeps the products of one currency, so that is
            // the lookup worth an index — and it stays small, because the
            // ordinary catalogue has none of these at all.
            $table->index('price_currency', 'm_catalog_products_price_currency_index');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropIndex('m_catalog_products_price_currency_index');
            $table->dropColumn(['price_currency', 'price_original', 'cost_price_original', 'list_price_original']);
        });
    }
};
