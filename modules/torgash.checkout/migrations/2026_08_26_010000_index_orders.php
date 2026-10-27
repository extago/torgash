<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the two order tables, all of them backed by a query that exists
 * today (see the catalog module's identical migration for why Postgres needs
 * these spelled out and MySQL does not):
 *
 *  - orders.user_id        — the customer card lists that customer's orders
 *                            (Admin\CustomersController::show).
 *  - orders.status         — OrderStatusesController blocks deleting a status
 *                            still in use with an exists() on it, and the list
 *                            screen filters by it.
 *  - orders.storefront_id  — a multi-storefront install scopes every list.
 *  - orders.created_at     — the admin list is latest()->paginate(20); without
 *                            it every page load sorts the whole table.
 *  - order_logs.order_id   — the order card reads its own history.
 *  - order_logs.user_id    — who did what, plus the FK-delete scan.
 */
return new class extends Migration
{
    protected array $indexes = [
        'm_checkout_orders' => ['user_id', 'status', 'storefront_id', 'created_at'],
        'm_checkout_order_logs' => ['order_id', 'user_id'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("create index if not exists {$table}_{$column}_index on {$table} ({$column})");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("drop index if exists {$table}_{$column}_index");
            }
        }
    }
};
