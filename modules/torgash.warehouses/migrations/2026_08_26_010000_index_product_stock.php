<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * m_warehouse_product_stock's primary key is (warehouse_id, product_id), so
 * every lookup that starts from a PRODUCT — which is all of them: isSplit(),
 * prime(), the per-product breakdown, syncAggregate() — could not use it and
 * scanned the table. It also makes deleting a product scan this table to
 * check the foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists m_warehouse_product_stock_product_id_index on m_warehouse_product_stock (product_id)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists m_warehouse_product_stock_product_id_index');
    }
};
