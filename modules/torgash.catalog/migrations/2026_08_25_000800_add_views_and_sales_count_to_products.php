<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feeds a "Hit"/bestseller label — views_count is bumped once per session
 * per product (StorefrontController::product()), sales_count is bumped by
 * qty when an order is placed (checkout.order_placed listener in
 * torgash.checkout, the same hook OrderLog/emails already use). Both plain
 * counters, not time-windowed — a merchant wanting "hits this month" would
 * need a follow-up, not attempted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('sales_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropColumn(['views_count', 'sales_count']);
        });
    }
};
