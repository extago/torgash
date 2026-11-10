<?php

namespace Torgash\Age\Http\Admin;

use Illuminate\Support\Facades\DB;
use Torgash\Age\Rules;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;

/**
 * Where an age is set (PLAN §71): the whole shop, every category and every
 * product that has one, with what a visitor who has not confirmed their age
 * is shown — one list instead of opening forms to find out.
 */
class OverviewController
{
    public function __invoke(Rules $rules)
    {
        $categoryRows = DB::table('m_age_category_limits')->get()->keyBy('category_id');
        $productRows = DB::table('m_age_product_limits')->orderByDesc('updated_at')->limit(500)->get()->keyBy('product_id');

        return view('torgash.age::admin.overview', [
            'siteAge' => Rules::clampAge(setting('torgash.age', 'site_age', 0)),
            'defaultDisplay' => $rules->defaultDisplay(),
            'labels' => Rules::displayLabels(),
            'categories' => Category::whereIn('id', $categoryRows->keys())->get()
                ->map(fn (Category $category) => ['category' => $category, 'row' => $categoryRows[$category->id]])
                ->sortBy(fn ($entry) => $entry['category']->t('name'))->values(),
            'products' => Product::whereIn('id', $productRows->keys())->get()
                ->map(fn (Product $product) => ['product' => $product, 'row' => $productRows[$product->id]])
                ->sortBy(fn ($entry) => $entry['product']->t('name'))->values(),
            'productCount' => DB::table('m_age_product_limits')->count(),
        ]);
    }
}
