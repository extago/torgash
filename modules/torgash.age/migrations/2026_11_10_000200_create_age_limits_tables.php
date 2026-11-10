<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Age limits (PLAN §71): an age on a category — it holds everything under
 * it — or on a product, with what a visitor who has not said they are old
 * enough is shown (`display`: show / cover / hide; null — the shop's
 * default, the setting of the same name). The whole shop's age is a setting.
 *
 * And the note an order of such goods carries: the oldest age among its
 * lines and when the buyer ticked "I am that old" — null when nobody asked
 * (a manager's order, the API, 1C). A row per order with an age, no row for
 * the others.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_age_category_limits', function (Blueprint $table) {
            $table->foreignId('category_id')->primary()->constrained('m_catalog_categories')->cascadeOnDelete();
            $table->unsignedSmallInteger('age');
            $table->string('display', 8)->nullable();
            $table->timestamps();
        });

        Schema::create('m_age_product_limits', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained('m_catalog_products')->cascadeOnDelete();
            $table->unsignedSmallInteger('age');
            $table->string('display', 8)->nullable();
            $table->timestamps();
        });

        Schema::create('m_age_orders', function (Blueprint $table) {
            $table->foreignId('order_id')->primary()->constrained('m_checkout_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('age');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_age_orders');
        Schema::dropIfExists('m_age_product_limits');
        Schema::dropIfExists('m_age_category_limits');
    }
};
