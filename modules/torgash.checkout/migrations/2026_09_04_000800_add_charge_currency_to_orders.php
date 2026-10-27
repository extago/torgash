<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer was actually charged in, beside what the shop counts in.
 *
 * These are two different questions and the shop only ever answered the first:
 * a merchant may keep prices in dollars because that is how the goods are
 * bought, while the customer switches the storefront to hryvnia and pays in
 * hryvnia — which is also the only currency the acquiring takes. Until now the
 * order was quoted, recorded and charged in the shop's own currency, so the
 * customer paid in a currency they had explicitly switched away from.
 *
 * `currency_code` keeps its meaning: the shop's books, and the units every
 * amount on the row (`total`, `paid_total`, item lines) is stored in. Nothing
 * about the books changes — a shop's reports must not move because a customer
 * used a switcher.
 *
 * `charge_currency` is what the customer saw and paid, and `charge_rate` is the
 * rate it was converted at, FROZEN. The rate has to be on the order: it is the
 * only thing that makes tomorrow's invoice show the same number as yesterday's
 * receipt, and the only way to read a gateway's callback (which speaks the
 * currency it was asked to charge) back into the shop's own units.
 *
 * Null in both means "the same as the books", which is what every order placed
 * before this — and every single-currency shop — is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->string('charge_currency', 3)->nullable()->after('currency_code');
            // 8 decimal places, like the currency table's own rate column: a
            // rate is a rate wherever it is written down.
            $table->decimal('charge_rate', 18, 8)->nullable()->after('charge_currency');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn(['charge_currency', 'charge_rate']);
        });
    }
};
