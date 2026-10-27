<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rate priced in a currency that is not the shop's base one.
 *
 * The case is ordinary: a Ukrainian shop keeps its catalogue in dollars because
 * suppliers quote in dollars, but Nova Poshta charges hryvnia and the courier
 * tariff was agreed in hryvnia. Writing 45.50 into a dollar field and hoping
 * the exchange rate never moves is how a delivery price silently drifts.
 *
 * NULL means "the base currency", which is what every existing rate is — so
 * nothing is rewritten and nothing downstream changes: the amounts are
 * converted to base minor units at quote time (see Torgash\Shipping\Money), and
 * the order, the totals and the taxes go on speaking one currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_shipping_rates', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('free_over');
        });
    }

    public function down(): void
    {
        Schema::table('m_shipping_rates', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
