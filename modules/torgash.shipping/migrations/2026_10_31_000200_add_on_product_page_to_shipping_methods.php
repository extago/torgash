<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a method is listed in the product page's "Delivery" block
 * (ProductEstimate). "Decide with the manager" says nothing a shopper
 * comparing prices needs — it stays at checkout and leaves the product page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_shipping_methods', function (Blueprint $table) {
            $table->boolean('on_product_page')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('m_shipping_methods', function (Blueprint $table) {
            $table->dropColumn('on_product_page');
        });
    }
};
