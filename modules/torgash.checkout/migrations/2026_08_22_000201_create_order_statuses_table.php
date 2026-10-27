<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order status set, modeled after the proven inventory-aware statuses of
 * established engines. Each status declares whether being in it means stock
 * is committed
 * ("decrease") or released back ("increase") — see Order::$stock_deducted and
 * OrdersController::updateStatus() for how the delta is applied on transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_checkout_order_statuses', function (Blueprint $table) {
            $table->string('code', 32)->primary();
            $table->jsonb('name');
            $table->string('color', 7);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_default')->default(false);
            $table->string('inventory_action', 8)->default('none'); // decrease | increase | none
            $table->timestamps();
        });

        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->boolean('stock_deducted')->default(false)->after('status');
        });

        DB::table('m_checkout_order_statuses')->insert([
            ['code' => 'open', 'name' => '{"en":"Open","ru":"Открыт"}', 'color' => '#ff9522', 'position' => 0, 'is_default' => true, 'inventory_action' => 'decrease', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'processing', 'name' => '{"en":"Processing","ru":"Обработан"}', 'color' => '#97cf4d', 'position' => 1, 'is_default' => false, 'inventory_action' => 'decrease', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'complete', 'name' => '{"en":"Complete","ru":"Выполнен"}', 'color' => '#16a34a', 'position' => 2, 'is_default' => false, 'inventory_action' => 'decrease', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'backordered', 'name' => '{"en":"Backordered","ru":"Отложен"}', 'color' => '#28abf6', 'position' => 3, 'is_default' => false, 'inventory_action' => 'increase', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'declined', 'name' => '{"en":"Declined","ru":"Отклонен"}', 'color' => '#ef4444', 'position' => 4, 'is_default' => false, 'inventory_action' => 'increase', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'failed', 'name' => '{"en":"Failed","ru":"Неудача"}', 'color' => '#f97316', 'position' => 5, 'is_default' => false, 'inventory_action' => 'increase', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'cancelled', 'name' => '{"en":"Cancelled","ru":"Аннулирован"}', 'color' => '#9ca3af', 'position' => 6, 'is_default' => false, 'inventory_action' => 'increase', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn('stock_deducted');
        });
        Schema::dropIfExists('m_checkout_order_statuses');
    }
};
