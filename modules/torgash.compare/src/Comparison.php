<?php

namespace Torgash\Compare;

use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;

/**
 * What this visitor is comparing — the one place that knows (PLAN §35.4).
 *
 * In the SESSION, as other engines keep it: a comparison is the business of one
 * visit, and a table row for everyone who ever pressed the button is a table
 * nobody reads. `product id => when it was added`.
 *
 * Products are compared by GROUP, because a laptop beside a phone case is a
 * table of dashes: a product's group is its DEEPEST enabled category (a
 * product has no "main" category; the deepest is the narrowest, i.e. the most
 * comparable), the smaller id on a tie, 0 for a product in none. A group
 * holds at most `per_group_max`; one more pushes out the one added first.
 *
 * A per-request singleton: every button on a page asks has().
 */
class Comparison
{
    public const SESSION = 'compare';

    /** Nobody compares fifty things; a session is not a place to grow a list. */
    public const TOTAL_MAX = 40;

    /** @var array<int, int>|null */
    protected ?array $groupOf = null;

    /** @return array<int, int> product id => added at (unix time), oldest first */
    public function ids(): array
    {
        $ids = [];

        foreach ((array) session(self::SESSION, []) as $id => $at) {
            if ((int) $id > 0) {
                $ids[(int) $id] = (int) $at;
            }
        }

        asort($ids);

        return $ids;
    }

    public function count(): int
    {
        return count($this->ids());
    }

    public function has(Product|int $product): bool
    {
        return isset($this->ids()[$product instanceof Product ? $product->id : $product]);
    }

    /**
     * @return Product|null the product pushed out to make room — the group was
     *                      full — so the notice can say which
     */
    public function add(Product $product): ?Product
    {
        $ids = $this->ids();
        $pushed = null;
        $group = $this->groupOf($product->id);
        $inGroup = array_keys(array_filter($ids, fn ($at, $id) => $this->groupOf($id) === $group, ARRAY_FILTER_USE_BOTH));
        $max = max(1, (int) setting('torgash.compare', 'per_group_max'));

        if (count($inGroup) >= $max) {
            $pushed = Product::find($inGroup[0]);
            unset($ids[$inGroup[0]]);
        }

        // Past the overall cap the oldest goes, whatever its group.
        while (count($ids) >= self::TOTAL_MAX) {
            unset($ids[array_key_first($ids)]);
        }

        // Strictly later than anything already there, so "the group added to
        // last" is an answer even for two clicks in one second.
        $ids[$product->id] = max(time(), $ids === [] ? 0 : max($ids) + 1);
        session([self::SESSION => $ids]);

        return $pushed;
    }

    /** @return bool whether it is in the comparison now, plus the product it pushed out */
    public function toggle(Product $product): array
    {
        if ($this->has($product)) {
            $this->remove($product->id);

            return [false, null];
        }

        return [true, $this->add($product)];
    }

    public function remove(int $productId): void
    {
        $ids = $this->ids();
        unset($ids[$productId]);
        session([self::SESSION => $ids]);
    }

    public function clear(): void
    {
        session()->forget(self::SESSION);
    }

    /**
     * Forget what can no longer be shown (deleted, disabled): the page asks,
     * and a product that has gone stops counting in the header too.
     *
     * @param  list<int>  $reachable
     */
    public function keepOnly(array $reachable): void
    {
        $ids = array_intersect_key($this->ids(), array_flip($reachable));

        if (count($ids) !== $this->count()) {
            session([self::SESSION => $ids]);
        }
    }

    /**
     * The tabs: each group with its products, oldest first; groups in the
     * order their first product was added, so a tab does not jump about.
     *
     * @return array<int, list<int>> group id => product ids
     */
    public function groups(): array
    {
        $groups = [];

        foreach (array_keys($this->ids()) as $id) {
            $groups[$this->groupOf($id)][] = $id;
        }

        return $groups;
    }

    /** The group added to last — the tab the page opens on. */
    public function latestGroup(): ?int
    {
        $ids = $this->ids();

        return $ids === [] ? null : $this->groupOf((int) array_key_last($ids));
    }

    public function groupOf(int $productId): int
    {
        if ($this->groupOf === null || ! array_key_exists($productId, $this->groupOf)) {
            $this->resolveGroups(array_unique([...array_keys($this->ids()), $productId]));
        }

        return $this->groupOf[$productId] ?? 0;
    }

    /** @param  list<int>  $productIds */
    protected function resolveGroups(array $productIds): void
    {
        $tree = app(CategoryTree::class);
        $this->groupOf ??= [];
        $candidates = [];

        foreach (DB::table('m_catalog_category_product')->whereIn('product_id', $productIds)->get(['product_id', 'category_id']) as $row) {
            $category = $tree->find((int) $row->category_id);

            if ($category && $category->isListed()) {
                $candidates[(int) $row->product_id][] = $category->id;
            }
        }

        foreach ($productIds as $id) {
            $ids = $candidates[$id] ?? [];
            usort($ids, fn ($a, $b) => [$tree->depth($b), $a] <=> [$tree->depth($a), $b]);
            $this->groupOf[$id] = $ids[0] ?? 0;
        }
    }
}
