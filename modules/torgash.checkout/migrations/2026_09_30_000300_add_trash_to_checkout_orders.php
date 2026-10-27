<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order can be moved to the trash — a test order, spam through the one-click
 * form — and brought back from it, the way a product or a category can. Not a
 * DELETE straight away: an order is the one record a shop cannot rebuild from
 * anything else, so the trash is where "gone" gets a second look.
 * See Torgash\Checkout\Orders\OrderTrash for what trashing does to the stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->softDeletes();
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};
