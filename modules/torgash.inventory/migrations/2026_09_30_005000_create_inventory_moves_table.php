<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every movement of stock and why (PLAN §42): one row per change of one
 * number — a warehouse's quantity of a product, or the product's own stock
 * when it is not kept per warehouse (`warehouse_id` NULL). Written from
 * `catalog.stock.moved`, which the catalogue and the warehouses announce from
 * the only places a number changes, with the cause the act put in force
 * (an order, a return, a merge, a document, an import, 1C, a person's edit).
 *
 * No foreign key to the product: a deleted product's history is still what
 * happened, and `sku` keeps it readable. `ref` is the rest of the cause —
 * whatever its owner said beyond the columns here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_inventory_moves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('sku', 190)->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->integer('delta');
            $table->integer('after')->nullable();
            $table->string('type', 16);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->jsonb('ref')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 190)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'id']);
            $table->index(['warehouse_id', 'id']);
            $table->index('order_id');
            $table->index('document_id');
            $table->index(['type', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_inventory_moves');
    }
};
