<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scopes a qty-break tier row to one named price type. Null keeps a row's
 * existing meaning exactly (applies under the base/retail type, as before
 * this migration) — the unique index has to widen to match, or a base-type
 * tier and a wholesale-type tier at the same (product, min_qty, group)
 * would collide on insert. Safe for existing rows: they're all
 * price_type_id IS NULL, which COALESCEs to the same 0 as before, so the
 * rewritten index is a no-op for anything already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_product_prices', function (Blueprint $table) {
            $table->foreignId('price_type_id')->nullable()->after('customer_group_id')
                ->constrained('m_catalog_price_types')->nullOnDelete();
        });

        DB::statement('DROP INDEX m_catalog_product_prices_unique');
        DB::statement(
            'CREATE UNIQUE INDEX m_catalog_product_prices_unique ON m_catalog_product_prices '.
            '(product_id, min_qty, COALESCE(customer_group_id, 0), COALESCE(price_type_id, 0))'
        );

        Schema::create('m_catalog_product_price_type_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('m_catalog_products')->cascadeOnDelete();
            $table->foreignId('price_type_id')->constrained('m_catalog_price_types')->cascadeOnDelete();
            $table->integer('price');
            $table->timestamps();
            $table->unique(['product_id', 'price_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_catalog_product_price_type_overrides');

        DB::statement('DROP INDEX m_catalog_product_prices_unique');
        Schema::table('m_catalog_product_prices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_type_id');
        });
        DB::statement(
            'CREATE UNIQUE INDEX m_catalog_product_prices_unique ON m_catalog_product_prices '.
            '(product_id, min_qty, COALESCE(customer_group_id, 0))'
        );
    }
};
