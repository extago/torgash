<?php

namespace Torgash\Catalog\Support;

use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;

/**
 * How many products the visitor is shown in each of these categories — the
 * list beside the catalogue (CategoryNav) and the tiles of a category's
 * subcategories ask the same question, so it is answered once, here.
 *
 * Counts are the visitor's: `Product::listed()` (a closed assortment hides
 * goods, and a number that counts them promises a grid that is not there),
 * and a category counts its whole subtree, like the listing it links to —
 * DISTINCT, because a product filed in two leaves of one branch is one
 * product. One query, however many categories.
 */
class CategoryCounts
{
    /**
     * @param  iterable<Category|int>  $categories
     * @return array<int, int>  category id => products; a category with none is absent
     */
    public function of(iterable $categories): array
    {
        [$owners, $members] = self::subtrees($categories);

        if ($owners === []) {
            return [];
        }

        return DB::query()
            ->fromRaw('unnest(?::bigint[], ?::bigint[]) as nav(owner, member)', [
                '{'.implode(',', $owners).'}',
                '{'.implode(',', $members).'}',
            ])
            ->join('m_catalog_category_product as cp', 'cp.category_id', '=', 'nav.member')
            ->whereIn('cp.product_id', Product::listed()->select('m_catalog_products.id')->toBase())
            ->groupBy('nav.owner')
            ->selectRaw('nav.owner, count(distinct cp.product_id) as n')
            ->pluck('n', 'owner')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Each category paired with every category of its subtree, itself
     * included — the two arrays a query unnests side by side.
     *
     * @param  iterable<Category|int>  $categories
     * @return array{0: list<int>, 1: list<int>}
     */
    public static function subtrees(iterable $categories): array
    {
        $tree = app(CategoryTree::class);
        $owners = [];
        $members = [];

        foreach ($categories as $category) {
            $id = $category instanceof Category ? (int) $category->id : (int) $category;

            foreach ($tree->descendants($id) as $member) {
                $owners[] = $id;
                $members[] = (int) $member;
            }
        }

        return [$owners, $members];
    }
}
