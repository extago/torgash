<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes on the referencing side of this module's foreign keys.
 *
 * Postgres indexes the REFERENCED column (it has to — it is a primary key) but
 * never the referencing one, unlike MySQL, which creates it silently. Two
 * things suffer without it, and both grow with the catalogue:
 *
 *  1. Reverse lookups scan. m_catalog_label_product(product_id) is read on
 *     every product grid (Label::forProducts), m_catalog_product_features
 *     (feature_id, feature_value_id) on every sidebar filter, and each was a
 *     sequential scan of the whole pivot.
 *  2. Deleting a parent row scans every child table. Removing one feature
 *     value made Postgres read all of m_catalog_product_features to prove no
 *     row referenced it — with the delete holding a lock the whole time.
 *
 * Composites are listed leading-column-first for the query that motivates
 * them; a plain single-column index is used where nothing pairs with it.
 */
return new class extends Migration
{
    /** column list => index name suffix, in "table" => [columns...] form. */
    protected array $indexes = [
        'm_catalog_categories' => [['parent_id']],
        'm_catalog_category_product' => [['product_id']],
        'm_catalog_feature_category' => [['category_id']],
        'm_catalog_features' => [['group_id']],
        'm_catalog_label_product' => [['product_id']],
        'm_catalog_price_type_customer_group' => [['customer_group_id']],
        'm_catalog_price_type_markup_rule_category' => [['category_id']],
        'm_catalog_price_type_markup_rule_feature_value' => [['feature_value_id']],
        'm_catalog_price_type_markup_rules' => [['price_type_id']],
        // The sidebar filters on both together (feature_id + whereIn value ids),
        // and the reverse lookup "which products carry this value" needs the
        // value on its own.
        'm_catalog_product_features' => [['feature_id', 'feature_value_id'], ['feature_value_id']],
        'm_catalog_product_price_type_overrides' => [['price_type_id']],
        'm_catalog_product_prices' => [['customer_group_id'], ['price_type_id']],
        'm_catalog_related_products' => [['related_product_id']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $sets) {
            foreach ($sets as $columns) {
                $name = $this->name($table, $columns);
                DB::statement(sprintf(
                    'create index if not exists %s on %s (%s)',
                    $name, $table, implode(', ', $columns)
                ));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $sets) {
            foreach ($sets as $columns) {
                DB::statement('drop index if exists '.$this->name($table, $columns));
            }
        }
    }

    /** Postgres caps identifiers at 63 bytes and silently truncates past it. */
    protected function name(string $table, array $columns): string
    {
        $name = $table.'_'.implode('_', $columns).'_index';

        return strlen($name) <= 63 ? $name : substr($table, 0, 40).'_'.substr(md5($name), 0, 12).'_index';
    }
};
