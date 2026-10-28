<?php

namespace Torgash\Catalog\Support;

use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;

/**
 * The category list beside the catalogue, as rows ready to draw.
 *
 * Two shapes, chosen by the shop (`category_nav_mode`):
 *
 * - **tree** — the top categories, opened along the branch the visitor is
 *   standing on. The sidebar used to list ROOT categories and nothing else,
 *   so in a shop whose products live one level down — which is every shop —
 *   the second level was reachable from no page at all.
 * - **branch** — only where the visitor is: the categories above as "back"
 *   rows, the current one, and what is under it. A leaf has nothing under it,
 *   so it is shown among its siblings instead — the list never ends on a
 *   single highlighted row with nowhere to go but up.
 *
 * Rows are flat with a depth, because a Blade partial that recurses is harder
 * to read than the lines here that decide visibility. A row's category is null
 * for "All products".
 *
 * Counts are the visitor's, a category's whole subtree, one query for every
 * row whatever the list's length — {@see CategoryCounts}, which the tiles of
 * a category's subcategories ask too.
 */
class CategoryNav
{
    /**
     * @return list<array{category: ?Category, kind: 'up'|'current'|'item', depth: int, open: bool, count: ?int}>
     */
    public function rows(?Category $current): array
    {
        $mode = (string) setting('torgash.catalog', 'category_nav_mode');

        if ($mode === 'off') {
            return [];
        }

        $rows = $mode === 'branch' ? $this->branch($current) : $this->tree($current);

        if (setting('torgash.catalog', 'category_nav_all_link')) {
            array_unshift($rows, $this->row(null, match (true) {
                $current === null => 'current',
                $mode === 'branch' => 'up',
                default => 'item',
            }));
        }

        $counts = (bool) setting('torgash.catalog', 'category_nav_counts');
        $hideEmpty = (bool) setting('torgash.catalog', 'category_nav_hide_empty');

        if ($counts || $hideEmpty) {
            $found = $this->counts($rows);

            foreach ($rows as $i => $row) {
                $rows[$i]['count'] = $found[$row['category']?->id ?? 0] ?? 0;
            }

            // Never the row the visitor is on, nor the way back up: an empty
            // category the visitor has reached still needs its own name.
            if ($hideEmpty) {
                $rows = array_values(array_filter($rows, fn ($row) => $row['kind'] !== 'item' || $row['count'] > 0));
            }

            if (! $counts) {
                foreach ($rows as $i => $row) {
                    $rows[$i]['count'] = null;
                }
            }
        }

        return $rows;
    }

    protected function tree(?Category $current): array
    {
        $tree = app(CategoryTree::class);
        $path = $current ? $tree->ancestors((int) $current->id, includeSelf: true) : [];
        $rows = [];

        foreach ($tree->ordered(listedOnly: true) as $category) {
            $depth = $tree->depth((int) $category->id);

            if ($depth > 0 && ! in_array((int) $category->parent_id, $path, true)) {
                continue;
            }

            $rows[] = $this->row(
                $category,
                (int) $current?->id === (int) $category->id ? 'current' : 'item',
                $depth,
                in_array((int) $category->id, $path, true),
            );
        }

        return $rows;
    }

    protected function branch(?Category $current): array
    {
        $tree = app(CategoryTree::class);
        $listed = $tree->ordered(listedOnly: true);
        $childrenOf = fn (int $parentId) => $listed->filter(fn (Category $c) => (int) $c->parent_id === $parentId)->values();

        if (! $current) {
            return $childrenOf(0)->map(fn (Category $c) => $this->row($c, 'item'))->all();
        }

        $rows = [];

        foreach (array_reverse($tree->ancestors((int) $current->id)) as $id) {
            if ($ancestor = $tree->find($id)) {
                $rows[] = $this->row($ancestor, 'up');
            }
        }

        $children = $childrenOf((int) $current->id);

        if ($children->isNotEmpty()) {
            $rows[] = $this->row($current, 'current', open: true);

            foreach ($children as $child) {
                $rows[] = $this->row($child, 'item', 1);
            }

            return $rows;
        }

        foreach ($childrenOf((int) $current->parent_id) as $sibling) {
            $rows[] = $this->row($sibling, (int) $sibling->id === (int) $current->id ? 'current' : 'item');
        }

        // A category reached by its address but not listed is not among its
        // own siblings — it still gets its row, or the list forgets where the
        // visitor is.
        if (! collect($rows)->contains(fn ($row) => $row['kind'] === 'current')) {
            $rows[] = $this->row($current, 'current');
        }

        return $rows;
    }

    /** @return array<int, int> category id (0 = all products) => products in its subtree */
    protected function counts(array $rows): array
    {
        $counts = app(CategoryCounts::class)->of(
            collect($rows)->pluck('category')->filter()->all()
        );

        if (collect($rows)->contains(fn ($row) => $row['category'] === null)) {
            $counts[0] = Product::listed()->count();
        }

        return $counts;
    }

    protected function row(?Category $category, string $kind, int $depth = 0, bool $open = false): array
    {
        return ['category' => $category, 'kind' => $kind, 'depth' => $depth, 'open' => $open, 'count' => null];
    }
}
