<?php

namespace Torgash\Catalog\Features;

use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;

/**
 * Which of the shop's characteristics a product's form shows (PLAN §63.9).
 *
 * Not all of them: a shop with a thousand characteristics has twenty per
 * category, and the form used to draw every one with every value of every
 * list. The set is what the shop itself declared — the characteristics
 * bound to any of the product's categories (Feature::categories(), "empty
 * = applies to every category") — plus whatever the product already has a
 * value for, however it got it. Anything else is reached by name through
 * search() and joins the form for this edit; the save touches only what
 * the form sent (ProductsController::syncGlobalFeatures).
 */
final class ProductFeatureSet
{
    /**
     * @param  list<int>  $categoryIds  the product's categories as the form holds them
     * @return Collection<int, Feature> with group and values loaded, in group then sort order
     */
    public function for(?Product $product, array $categoryIds): Collection
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        $assigned = $product?->exists ? $product->featureAssignments()->pluck('feature_id')->all() : [];

        return $this->query()
            ->where(fn ($q) => $q
                ->whereDoesntHave('categories')
                ->when($categoryIds !== [], fn ($q) => $q->orWhereHas('categories', fn ($c) => $c->whereIn('m_catalog_categories.id', $categoryIds)))
                ->when($assigned !== [], fn ($q) => $q->orWhereIn('id', $assigned)))
            ->get();
    }

    /**
     * Characteristics found by name in any language or by code, the ones
     * already on the form left out.
     *
     * @param  list<int>  $exclude
     * @return Collection<int, Feature>
     */
    public function search(string $needle, array $exclude = [], int $limit = 12): Collection
    {
        $needle = trim($needle);

        if ($needle === '') {
            return collect();
        }

        return $this->query()
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('id', array_map('intval', $exclude)))
            ->where(fn ($q) => $q
                ->where('code', 'ilike', '%'.$needle.'%')
                ->orWhereRaw('exists (select 1 from jsonb_each_text(name) as t(k, v) where t.v ilike ?)', ['%'.$needle.'%']))
            ->limit($limit)
            ->get();
    }

    protected function query()
    {
        return Feature::query()->with(['group', 'values'])->orderBy('group_id')->orderBy('sort')->orderBy('id');
    }
}
