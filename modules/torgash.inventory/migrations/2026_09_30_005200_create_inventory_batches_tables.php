<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batches and what each movement cost (PLAN §42).
 *
 * A BATCH is units that arrived together at one price: a receipt's line (its
 * frozen price), or anything else that added stock — an import, 1C, a number
 * typed by hand, a count's surplus — at an UNKNOWN price (`unit_cost` NULL),
 * which the profit report names as unknown rather than guessing. A
 * `warehouse_id` of NULL is the product's own column (a product not kept per
 * warehouse).
 *
 * `m_inventory_move_costs` says which batches a movement took from or gave
 * back to, and at what price: a sale takes the oldest first (FIFO), a
 * transfer carries its batches' prices to the other warehouse, a return or a
 * cancelled order puts the units back into the batches they left. Units taken
 * where no batch had any are a row with no batch and no price.
 *
 * On install, what is already on the shelves becomes one OPENING batch per
 * product and place, at an unknown price — the oldest units, so FIFO sells
 * them before anything received later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->integer('qty');
            $table->integer('remaining');
            $table->bigInteger('unit_cost')->nullable();
            $table->string('source', 16);
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('move_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'warehouse_id', 'id']);
            $table->index('document_id');
        });

        Schema::create('m_inventory_move_costs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('move_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            // Units, always positive; the movement's own sign says which way.
            $table->integer('qty');
            $table->bigInteger('unit_cost')->nullable();

            $table->index('move_id');
            $table->index('batch_id');
        });

        $now = now();

        DB::table('m_warehouse_product_stock')->where('qty', '>', 0)->orderBy('product_id')
            ->each(function ($row) use ($now) {
                DB::table('m_inventory_batches')->insert([
                    'product_id' => $row->product_id, 'warehouse_id' => $row->warehouse_id,
                    'qty' => $row->qty, 'remaining' => $row->qty, 'unit_cost' => null,
                    'source' => 'opening', 'created_at' => $now,
                ]);
            });

        DB::table('m_catalog_products')->where('stock', '>', 0)
            ->whereNotIn('id', DB::table('m_warehouse_product_stock')->select('product_id'))
            ->orderBy('id')
            ->each(function ($product) use ($now) {
                DB::table('m_inventory_batches')->insert([
                    'product_id' => $product->id, 'warehouse_id' => null,
                    'qty' => $product->stock, 'remaining' => $product->stock, 'unit_cost' => null,
                    'source' => 'opening', 'created_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_inventory_move_costs');
        Schema::dropIfExists('m_inventory_batches');
    }
};
