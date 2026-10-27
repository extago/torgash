<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cart stops living in the session.
 *
 * It lived there because a session is where a browser's cart naturally goes,
 * and for a browser that worked. Three things it cannot do, and all three are
 * now in front of us:
 *
 * 1. **An API client has no session.** §15.1.2 addresses a cart by an opaque
 *    token, and every checkout step is a write against it. A session cannot be
 *    handed to a mobile app.
 * 2. **An abandoned cart is invisible.** It expires with the session, unread,
 *    so the shop cannot count them, mail about them or learn anything from
 *    them.
 * 3. **A cart cannot follow anybody.** Log in on a phone and the cart built on
 *    the laptop is not there — it was never anywhere but that browser.
 *
 * So: a row, addressed by a TOKEN. The browser keeps the token in its session
 * (one string instead of the contents), an API client keeps it wherever it
 * likes, and both are talking to the same object.
 *
 * `items` stays the shape the session held — `{"product_id": qty}` — because
 * that is what CartManager already speaks and changing both at once would make
 * this migration two migrations. Lines with their own options and frozen prices
 * are the next question, and jsonb leaves it open.
 *
 * `user_id` is stamped when a cart is used by somebody signed in, which is what
 * makes "your cart, on another device" possible later; it is nullable because
 * most carts belong to nobody at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_cart_carts', function (Blueprint $table) {
            $table->id();

            // The address. Random and long: it is the only thing standing
            // between a stranger and somebody else's cart.
            $table->string('token', 64)->unique();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('storefront_id')->nullable();

            $table->jsonb('items')->default('{}');
            $table->string('coupon_code')->nullable();

            // Touched on every write, which is what a "carts nobody came back
            // to" sweep counts from — and what the abandoned-cart question will
            // be asked of later.
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_active_at']);
            $table->index('last_active_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_cart_carts');
    }
};
