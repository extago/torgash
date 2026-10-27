<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deliberately scoped-down alternative to a full promotions engine
 * (the usual one keeps conditions and bonuses as serialized PHP blobs —
 * an arbitrary condition-tree rule engine, not a one-pass feature and not
 * something to guess the shape of without the store owner's actual business
 * rules). This covers the single most common, unambiguous case instead:
 * a coupon code for a percent or fixed discount, with a usage limit,
 * optional date window and minimum subtotal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_checkout_coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('type', 8); // percent | fixed
            $table->unsignedInteger('value'); // percent: 0-100; fixed: minor units
            $table->unsignedBigInteger('min_subtotal')->nullable(); // minor units
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->string('coupon_code')->nullable()->after('subtotal');
            $table->unsignedBigInteger('discount_amount')->default(0)->after('coupon_code');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn(['coupon_code', 'discount_amount']);
        });
        Schema::dropIfExists('m_checkout_coupons');
    }
};
