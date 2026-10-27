<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The third currency on an order: what the BANK bills.
 *
 * The other two are already there and neither of them answers this:
 *
 *   `currency_code`   the shop's books — what every amount on the row is stored
 *                     in, and what its reports are in.
 *   `charge_currency` what the customer was quoted in and what the order is
 *                     printed in for ever after (§51).
 *   `payment_currency` THIS one — what the acquiring actually takes.
 *
 * They genuinely differ all at once: a shop keeping books in dollars, a
 * customer browsing in euro, and a Ukrainian merchant account that can only be
 * paid in hryvnia. Folding the third into the second was the tempting shortcut
 * and it is wrong in both directions — printing the whole order in hryvnia
 * changes the numbers the customer just agreed to, and printing it in euro
 * leaves the Pay button quoting an amount no bank will ever charge.
 *
 * Frozen at placement, like the quote rate and for the same reason: the invoice
 * a customer pays tomorrow must be the amount they were shown today. NULL means
 * "the same as the quote", which is every order placed before this and every
 * shop whose payment methods take the currency they sell in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->string('payment_currency', 3)->nullable()->after('charge_rate');
            // Units of the payment currency per one unit of the BOOKS currency
            // — the same direction as `charge_rate` and as the currency table,
            // because a rate that points two ways depending on the column is
            // the bug this whole area keeps producing.
            $table->decimal('payment_rate', 18, 8)->nullable()->after('payment_currency');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn(['payment_currency', 'payment_rate']);
        });
    }
};
