<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the buyer IS, when the shop has no account for them.
 *
 * `user_id` answers it for a registered customer and is null for everybody
 * else, which is most orders in most shops — and every order that will come
 * through buying in one click, where a person types a phone number and nothing
 * else. Until now the only thing tying two such orders together was the email
 * address, and that is exactly the field a one-click order does not have.
 *
 * The tempting fix is the usual one: create a throwaway account with a made-up
 * address (`guest_1a2b@example.com`) so every downstream question has something
 * to join on. It works, and it fills the customer list with accounts nobody
 * opened, in the shop's own users table, for ever.
 *
 * So: a short opaque reference on the ORDER instead. Derived, not looked up —
 * the same buyer gets the same ref without a query and without an index, on
 * orders placed before and after (Torgash\Checkout\Orders\CustomerRef). It is
 * an identifier, never a credential: it does not authorise anything, and the
 * things that do — the cart token, the "pay later" link — stay what they are.
 *
 * What reads it today is the 1C exchange, which has to name a counterparty and
 * cannot name one "null".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            // Nullable: every order placed before this has none, and back-filling
            // one would be inventing a fact about who those people were.
            $table->string('customer_ref', 32)->nullable()->after('user_id');
            $table->index('customer_ref');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropIndex(['customer_ref']);
            $table->dropColumn('customer_ref');
        });
    }
};
