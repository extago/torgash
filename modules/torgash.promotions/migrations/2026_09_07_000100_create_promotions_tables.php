<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic (no-code) discounts, as two zones — the usual promotions model
 * (conditions and bonuses, zone catalog|cart, priority + stop rules). That
 * shape does not
 * actually serve a "Black Friday" sale well: one condition-set per promotion
 * means a sale with several discount depths ("10% off everything, 50% off
 * Brand X in Electronics") needs one promotion per depth, each guessing a
 * priority against the others.
 *
 * Here a promotion is a container of ordered TIERS instead. A tier is its own
 * condition scope plus its own discount; tiers are tried in order and the
 * FIRST one that matches wins for a given product/cart — the admin's own
 * ordering IS the specificity, same idea as a price tier or a shipping rate
 * row (an ordered list of scoped rows under one parent), and it means "Black
 * Friday" is one promotion — one on/off switch, one date range — with the
 * narrow tier listed first and the sitewide one as the fallback.
 *
 * `combine` is the answer to the OTHER half of the same story: how this
 * promotion interacts with a DIFFERENT one matching the same product/cart.
 * `replace` (the default) competes with other replace-mode promotions on
 * priority; `stack_add` sums its discount with whatever already applied;
 * `stack_multiply` applies to the already-discounted price — a real "double
 * discount", compounding rather than summing.
 *
 * torgash.checkout's Coupon stays separate on purpose: it is the simple
 * code+percent/fixed flow that already works. This engine is the automatic
 * layer on top, and nothing here migrates a coupon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_promotions_promotions', function (Blueprint $table) {
            $table->id();
            $table->jsonb('name');

            // catalog: changes what a product's effectivePrice() resolves to.
            // cart: an extra_totals line on the cart/order total.
            $table->string('zone', 8);

            // Lower runs first; a 'replace' promotion beats a later one for the
            // same product/cart, same idea as a shipping zone's tie-break order.
            $table->smallInteger('priority')->default(0);

            $table->string('combine', 16)->default('replace');

            $table->boolean('enabled')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Cart zone only, same idiom as Coupon's own usage_limit/used_count:
            // a total redemption cap plus "once per customer" (checked against
            // this promotion's own snapshot rows on m_promotions_order_promotions
            // — see Promotion::usableBy()). NULL limit = uncapped.
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('once_per_customer')->default(false);

            $table->timestamps();

            $table->index(['zone', 'enabled']);
        });

        Schema::create('m_promotions_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('m_promotions_promotions')->cascadeOnDelete();

            // Evaluation and "first match wins" order within the promotion.
            $table->unsignedInteger('position')->default(0);

            // {"match": "all"|"any", "rules": [{kind, operator, value...}]}.
            // Empty rules matches everything — the sitewide fallback tier.
            $table->jsonb('conditions')->nullable();

            // percent: 0-100 in discount_value. fixed: minor units in
            // discount_value. free_shipping (cart zone only): discount_value
            // unused. give_group/free_product (cart zone only) are NOT price
            // adjustments at all — see the columns below — so discount_value is
            // unused for them too. Same convention as Coupon's own type/value.
            $table->string('discount_type', 16)->default('percent');
            $table->unsignedInteger('discount_value')->nullable();

            // Restricts a percent/fixed cart-zone discount to only the matching
            // lines' own total rather than the whole subtotal — the usual
            // "discount on products / categories" bonuses, one shape
            // ({type: product|category, ids}) for both, the same reason a
            // condition rule already carries product/category ids as one list.
            // NULL means "the whole subtotal", which is every existing tier.
            $table->jsonb('discount_scope')->nullable();

            // give_group: upgrades the buyer's own customer group. A side
            // effect, not a price line — applied once, at real order placement
            // (checkout.order_placed), never speculatively while a live cart is
            // merely being re-priced.
            $table->foreignId('give_group_id')->nullable()->constrained('customer_groups')->nullOnDelete();

            // free_product: a gift line added to the order at placement — see
            // the `checkout.order.items` hook. Also a side effect, for the same
            // reason as give_group: it must fire exactly once, not on every
            // live re-price.
            $table->foreignId('free_product_id')->nullable()->constrained('m_catalog_products')->nullOnDelete();
            $table->unsignedInteger('free_qty')->default(1);

            $table->timestamps();

            $table->index(['promotion_id', 'position']);
        });

        /**
         * What a placed order was actually given, frozen — same reasoning as
         * m_taxes_order_taxes: a promotion edited or deleted next month must
         * not rewrite what an old order says it was charged.
         */
        Schema::create('m_promotions_order_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('m_checkout_orders')->cascadeOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained('m_promotions_promotions')->nullOnDelete();
            $table->string('name');
            $table->bigInteger('amount');

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_promotions_order_promotions');
        Schema::dropIfExists('m_promotions_tiers');
        Schema::dropIfExists('m_promotions_promotions');
    }
};
