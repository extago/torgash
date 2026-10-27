<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The answers given so far, on the cart they were given about.
 *
 * A browser's checkout is one page and one submit, so the answers never had to
 * live anywhere: they arrived together. An API's checkout is a conversation —
 * address, then the delivery options for that address, then a payment method —
 * and each step has to survive until the next one (§15.1.2 calls it a resumable
 * checkout, and that is exactly the property being bought).
 *
 * On the CART and not in a table of its own, because that is what these answers
 * belong to: the cart is already the thing addressed by a token, already the
 * thing that expires, and a half-finished checkout is not an entity a shop has
 * any other use for. When the order is placed the cart is emptied and this goes
 * with it.
 *
 * jsonb because the shape is whatever `PlaceOrder::rules()` asks for, and that
 * grows as the shop does — a column per field would be a migration every time
 * a module adds one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_cart_carts', function (Blueprint $table) {
            $table->jsonb('checkout')->nullable()->after('coupon_code');
        });
    }

    public function down(): void
    {
        Schema::table('m_cart_carts', function (Blueprint $table) {
            $table->dropColumn('checkout');
        });
    }
};
