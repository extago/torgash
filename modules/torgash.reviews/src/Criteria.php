<?php

namespace Torgash\Reviews;

use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Reviews\Models\Criterion;
use Torgash\Reviews\Models\CriterionCategory;

/**
 * Which criteria a given product is rated by.
 *
 * Two rules, both borrowed rather than invented, because a shop should not have
 * to learn a second set of words for the same idea:
 *
 * **Attached to nothing means everywhere.** Exactly how a filter
 * characteristic already behaves, and it is what keeps a shop that rates
 * everything by "Quality" and "Value" on an empty screen.
 *
 * **A category means itself and everything under it.** Read UPWARDS from the
 * product's own categories, so a criterion attached to "Laptops" is asked on
 * "Gaming laptops" too. There is no "include subcategories" switch here for
 * the same reason there is none anywhere else in this engine: two meanings of
 * the word "category" in one shop is how a rule ends up applying in a place
 * nobody can explain. The whole tree is one memoised read
 * ({@see CategoryTree}).
 *
 * A registered per-request singleton: the product page asks, the form asks,
 * the aggregate asks, and the answer costs two queries however many ask.
 */
class Criteria
{
    /** @var Collection<int, Criterion>|null */
    protected ?Collection $all = null;

    /** @var array<int, list<int>>|null criterion id => category ids */
    protected ?array $links = null;

    /** @var array<int, Collection<int, Criterion>> */
    protected array $memo = [];

    /** Every enabled criterion, in the shop's own order. */
    public function all(): Collection
    {
        return $this->all ??= Criterion::where('enabled', true)->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * The criteria asked about THIS product.
     *
     * @return Collection<int, Criterion>
     */
    public function forProduct(Product $product): Collection
    {
        return $this->memo[$product->id] ??= $this->resolve($product);
    }

    /** @return Collection<int, Criterion> */
    protected function resolve(Product $product): Collection
    {
        $criteria = $this->all();

        if ($criteria->isEmpty()) {
            return $criteria;
        }

        $tree = app(CategoryTree::class);
        $branch = [];

        // The product's categories AND everything above them: the rule is
        // written on the branch and applies to the leaf.
        foreach ($product->categories as $category) {
            foreach ($tree->ancestors((int) $category->id, includeSelf: true) as $id) {
                $branch[$id] = true;
            }
        }

        $links = $this->links();

        return $criteria->filter(function (Criterion $criterion) use ($links, $branch) {
            $wanted = $links[$criterion->id] ?? [];

            if ($wanted === []) {
                return true;
            }

            foreach ($wanted as $categoryId) {
                if (isset($branch[$categoryId])) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /** @return array<int, list<int>> */
    protected function links(): array
    {
        return $this->links ??= CriterionCategory::get(['criterion_id', 'category_id'])
            ->groupBy('criterion_id')
            ->map(fn ($rows) => $rows->pluck('category_id')->map('intval')->all())
            ->all();
    }

    /** For tests and for a screen that has just saved. */
    public function flush(): void
    {
        $this->all = null;
        $this->links = null;
        $this->memo = [];
    }
}
