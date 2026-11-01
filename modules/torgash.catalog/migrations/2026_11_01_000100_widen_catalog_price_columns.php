<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Prices per unit may be kept with four places (PLAN §65): 10 000 price
 * units to the major unit, so an integer column stops at 214 748 — less than
 * one laptop in hryvnias. Every price column still `integer` becomes `bigint`
 * like the product's own. Widening keeps every value; narrowing back would
 * not be safe, so down() leaves them wide.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'm_catalog_product_prices' => ['price', 'price_original'],
        'm_catalog_product_price_type_overrides' => ['price', 'price_original'],
        'm_catalog_price_type_markup_rules' => ['markup_value', 'round_to', 'psychological_offset'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE bigint");
            }
        }
    }

    public function down(): void
    {
        // Wide is a superset of narrow: nothing to put back.
    }
};
