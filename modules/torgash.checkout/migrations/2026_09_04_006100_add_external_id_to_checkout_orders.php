<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The accounting system's own number for this order.
 *
 * Orders travel in both directions — out to 1C when they are placed, back with
 * a document number, a shipment, a payment — and `order_number` is OURS: it is
 * printed on the invoice and must not become a foreign key into somebody else's
 * database. This is where their number goes.
 *
 * See the catalogue's migration of the same date for why the column is nullable
 * and unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('order_number');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn('external_id');
        });
    }
};
