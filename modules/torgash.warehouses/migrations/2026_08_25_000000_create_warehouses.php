<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modeled on the usual warehouses add-on (priority-ordered greedy deduction
 * across warehouses) and
 * cross-checked against Bitrix's own trade-catalog docs, which converge on the
 * same shape: a per-warehouse quantity table plus a priority order used when
 * writing stock off. Left out on purpose — both are addon/edition features in
 * their own products, not core, and neither is what was asked for here:
 * shipping-destination routing (other engines couple their warehouses to
 * store locator / destination add-ons for that), stock reservation with a TTL
 * (Bitrix's "резервирование" — holds stock at cart-add rather than at order
 * status change, which is a materially different mechanic), and any bulk
 * CSV/1C import UI.
 *
 * A product's stock counts as "split" purely by having at least one row in
 * m_warehouse_product_stock — no separate boolean flag column to drift out of
 * sync with that. m_catalog_products.stock stays the single source everything
 * already reads; this module's job is only to keep it equal to the sum of a
 * split product's warehouse rows (see StockAllocator::syncAggregate()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_warehouses', function (Blueprint $table) {
            $table->id();
            $table->jsonb('name');
            $table->string('address')->nullable();
            // Deduction priority: enabled warehouses are drained in ascending
            // order, same convention as every other "sort" column in Torgash.
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('enabled')->default(true);
            // Nothing writes this yet — reserved for a future 1C/ERP importer to
            // map its own warehouse GUID onto ours, the way CommerceML importers do
            // (a 1C id resolves to a local warehouse_id via a stored mapping).
            // Adding it now means that importer never needs a schema migration.
            $table->string('external_id')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('m_warehouse_product_stock', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->constrained('m_warehouses')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('m_catalog_products')->cascadeOnDelete();
            $table->integer('qty')->default(0);
            $table->primary(['warehouse_id', 'product_id']);
        });

        // Every real install has at least one physical location; ships inert
        // (no stock rows) so it changes nothing until an admin actually moves a
        // product's quantity into it.
        DB::table('m_warehouses')->insert([
            'name' => json_encode(['en' => 'Main warehouse', 'ru' => 'Основной склад'], JSON_UNESCAPED_UNICODE),
            'sort' => 0,
            'is_default' => true,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('m_warehouse_product_stock');
        Schema::dropIfExists('m_warehouses');
    }
};
