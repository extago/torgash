<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who in the shop is dealing with this order, plus the two indexes the order
 * list needs once it can be filtered.
 *
 * `manager_id` points at a staff account (a user holding a permission group),
 * nullable because "nobody assigned yet" is a real and common answer the list
 * can filter on — the same shape other engines' order search calls `issuer` / "no manager
 * assigned". nullOnDelete: deleting an employee must not take their orders.
 *
 * The GIN index is what makes "orders containing this product" answerable at
 * all: line items live in a jsonb column, so the filter is a containment test
 * (`items @> '[{"product_id": 5}]'`) and without the index it is a sequential
 * scan of every order ever placed. jsonb_path_ops is the smaller, faster
 * operator class and supports exactly the @> we use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });

        DB::statement('create index if not exists m_checkout_orders_manager_id_index on m_checkout_orders (manager_id)');
        DB::statement('create index if not exists m_checkout_orders_items_gin on m_checkout_orders using gin (items jsonb_path_ops)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists m_checkout_orders_items_gin');
        DB::statement('drop index if exists m_checkout_orders_manager_id_index');

        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
        });
    }
};
