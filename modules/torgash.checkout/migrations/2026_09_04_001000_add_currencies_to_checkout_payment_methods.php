<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which currencies a payment method actually takes, and what it does about the
 * ones it does not.
 *
 * This is a property of the METHOD, not of a gateway, which is why it lives in
 * checkout: "the courier only takes hryvnia" and "the invoice is issued against
 * our hryvnia account" are the same question asked of a method that has no
 * gateway at all. `torgash.payments` narrows the choice further to what the
 * acquiring can charge in (`Gateway::currencies()`), and warns when the two
 * disagree — but it does not own the answer.
 *
 * `currencies` — accepted, as a list of ISO codes. NULL means "any", which is
 * every method that exists today and every single-currency shop, so nothing
 * changes for them.
 *
 * `charge_currency` — what to charge in when the order is in a currency this
 * method does NOT accept. Filled in, the method stays on offer and the customer
 * is told the payment goes through in it (a dollar-priced order paid in
 * hryvnia, which is the ordinary Ukrainian shop). Left empty, the method simply
 * is not offered for such orders — that is the honest answer for a courier who
 * physically cannot take dollars.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_payment_methods', function (Blueprint $table) {
            $table->jsonb('currencies')->nullable()->after('instructions');
            $table->string('charge_currency', 3)->nullable()->after('currencies');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_payment_methods', function (Blueprint $table) {
            $table->dropColumn(['currencies', 'charge_currency']);
        });
    }
};
