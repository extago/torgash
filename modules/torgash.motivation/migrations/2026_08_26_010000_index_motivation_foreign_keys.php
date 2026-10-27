<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The three targeting pivots are keyed (item_id, target_id), which serves
 * "what does this item target". The storefront asks the opposite question —
 * "which items target THIS product/category/group" — on every product page,
 * and that side had no index. Deleting a product or a category also had to
 * scan these tables to check the foreign key.
 */
return new class extends Migration
{
    protected array $indexes = [
        'm_motivation_item_products' => 'product_id',
        'm_motivation_item_categories' => 'category_id',
        'm_motivation_item_customer_groups' => 'customer_group_id',
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $column) {
            DB::statement("create index if not exists {$table}_{$column}_index on {$table} ({$column})");
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $column) {
            DB::statement("drop index if exists {$table}_{$column}_index");
        }
    }
};
