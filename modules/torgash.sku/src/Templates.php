<?php

namespace Torgash\Sku;

use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;

/**
 * Which template a product's SKU is made by: its category's own, else the
 * nearest parent's, else the shop's (the module setting).
 *
 * A product in several categories takes the DEEPEST one — "Gaming laptops"
 * says more than "Electronics", and is where a shop puts its own template.
 * The table is a handful of rows, read once per request (a registered
 * singleton, never a static: a worker serves many requests).
 */
class Templates
{
    /** @var array<int, string>|null category id => template */
    protected ?array $byCategory = null;

    public function __construct(protected CategoryTree $tree) {}

    /** @return array{template: string, category: ?Category} */
    public function for(Product $product): array
    {
        $category = $this->categoryOf($product);

        foreach ($category ? $this->tree->ancestors($category->id, includeSelf: true) : [] as $id) {
            if (($own = $this->byCategory()[$id] ?? '') !== '') {
                return ['template' => $own, 'category' => $category];
            }
        }

        return ['template' => (string) setting('torgash.sku', 'template', 'SKU-{n:6}'), 'category' => $category];
    }

    /** The product's most specific category, or null for a product in none. */
    public function categoryOf(Product $product): ?Category
    {
        if (! $product->exists) {
            return null;
        }

        $ids = DB::table('m_catalog_category_product')->where('product_id', $product->getKey())->pluck('category_id')->all();

        return collect($ids)
            ->map(fn ($id) => $this->tree->find((int) $id))
            ->filter()
            ->sortByDesc(fn (Category $c) => count($this->tree->ancestors($c->id)))
            ->first();
    }

    /** @return array<int, string> */
    public function byCategory(): array
    {
        return $this->byCategory ??= DB::table('m_sku_category_templates')->pluck('template', 'category_id')
            ->map(fn ($t) => (string) $t)->all();
    }

    /** One category's own template; blank removes it (the parent's applies again). */
    public function put(int $categoryId, string $template): void
    {
        $template = trim($template);

        $template === ''
            ? DB::table('m_sku_category_templates')->where('category_id', $categoryId)->delete()
            : DB::table('m_sku_category_templates')->updateOrInsert(['category_id' => $categoryId], ['template' => $template, 'updated_at' => now(), 'created_at' => now()]);

        $this->byCategory = null;
    }

    public function flush(): void
    {
        $this->byCategory = null;
    }
}
