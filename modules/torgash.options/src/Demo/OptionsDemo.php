<?php

namespace Torgash\Options\Demo;

use App\Core\Demo\DemoPack;
use Torgash\Catalog\Demo\CatalogDemo;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Options\Models\OptionGroup;

/**
 * Its part of the demo shop (PLAN §55.3): questions a product asks before it
 * is bought — shared ones placed on categories by display rules (a warranty
 * priced as a fee plus a share, a TV's installation, gift wrapping) and a
 * laptop's own (memory as a choice, real products added to the order).
 *
 * Read from `database/demo/options/groups.json`: products by SKU, categories
 * by slug, money in US dollars converted as the catalogue's part converts
 * its prices. A group whose product the pack did not make is skipped.
 */
class OptionsDemo
{
    public function install(DemoPack $pack): void
    {
        $money = app(CatalogDemo::class);
        $made = 0;

        foreach ($pack->json('options/groups') as $sort => $row) {
            $product = isset($row['product']) ? Product::where('sku', $row['product'])->first() : null;

            if (isset($row['product']) && ! $product) {
                continue;
            }

            $categories = Category::whereIn('slug', (array) ($row['categories'] ?? []))->pluck('id')->all();

            $group = OptionGroup::create([
                'code' => $row['code'] ?? null,
                'product_id' => $product?->id,
                'admin_name' => $row['admin_name'] ?? null,
                'name' => $row['name'],
                'hint' => $row['hint'] ?? null,
                'type' => $row['type'] ?? 'select',
                'required' => (bool) ($row['required'] ?? false),
                'rules' => $categories ? [['kind' => 'product_category', 'operator' => 'in', 'value' => $categories]] : null,
                'rules_match' => 'all',
                'sort' => $sort,
                'enabled' => true,
            ]);

            foreach ((array) ($row['choices'] ?? []) as $choiceSort => $choice) {
                $kind = $choice['kind'] ?? 'plain';
                $linked = $kind === 'product' ? Product::where('sku', (string) ($choice['product'] ?? ''))->first() : null;

                if ($kind === 'product' && ! $linked) {
                    continue;
                }

                $group->choices()->create([
                    'kind' => $kind,
                    'name' => $choice['name'] ?? null,
                    'product_id' => $linked?->id,
                    'price_rule' => $choice['rule'] ?? 'fixed',
                    'amount' => isset($choice['amount']) ? $money->minor($pack, (float) $choice['amount']) : 0,
                    'percent' => $choice['percent'] ?? null,
                    'is_default' => (bool) ($choice['default'] ?? false),
                    'sort' => $choiceSort,
                    'enabled' => true,
                ]);
            }

            $made++;
        }

        $pack->say($made.' option groups.');
    }
}
