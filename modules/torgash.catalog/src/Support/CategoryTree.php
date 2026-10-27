<?php

namespace Torgash\Catalog\Support;

use Illuminate\Database\Eloquent\Collection;
use Torgash\Catalog\Models\Category;

/**
 * The category tree, read once per request, and the one answer to "this
 * category and everything under it".
 *
 * It exists because the shop kept forgetting the tree was a tree. Sixteen
 * screens offered categories as `Category::orderBy('sort')->get()` — a flat
 * list in which "Laptops" and "Gaming laptops" are siblings in an order that
 * means nothing — and every rule that matched on a category compared DIRECT
 * membership, so "products in Electronics" matched nothing in a shop that keeps
 * its products in the leaves. The storefront did the same: a parent category
 * page showed an empty grid, and its children were not linked from anywhere.
 *
 * So the rule is stated once, here, and it is short: **a category is its
 * subcategories.** Asking about Electronics asks about everything beneath it,
 * at any depth. We could not construct a case where a shopper or a shop owner
 * meant the other thing — and the one place a genuine distinction does exist
 * (which categories a generating rule should WRITE PAGES IN — four or forty)
 * says so with its own switch, `FilterRule::with_children`.
 *
 * A registered singleton rather than a `static`, like Support\Labels: the memo
 * dies with the request even under a long-lived worker. The whole table is tens
 * of rows and half the shop asks about it, so reading it once and walking it in
 * PHP beats a recursive CTE per question.
 *
 * Depth is kept HERE and never written onto the model: an Eloquent model has no
 * spare properties — anything assigned becomes an attribute, and an attribute
 * becomes a column the next time somebody saves that row.
 */
class CategoryTree
{
    /** @var Collection<int, Category>|null every category, keyed by id */
    protected ?Collection $all = null;

    /** @var array<int, list<int>>|null parent id (0 = root) => child ids, in the shop's order */
    protected ?array $children = null;

    /** @var array<int, int> category id => how deep it sits, filled by ordered() */
    protected array $depths = [];

    /** @var array<string, list<int>> memo for descendants(), keyed by the question */
    protected array $subtrees = [];

    /** @return Collection<int, Category> */
    public function all(): Collection
    {
        return $this->all ??= Category::orderBy('sort')->orderBy('id')->get()->keyBy('id');
    }

    public function find(int $id): ?Category
    {
        return $this->all()->get($id);
    }

    /**
     * These categories and everything beneath them, at any depth.
     *
     * The ids come back including the ones asked about, because every caller
     * wants "the set this names" rather than "what is under it" — and an id
     * naming a category that no longer exists is KEPT rather than dropped, so a
     * rule pointing at a deleted category goes on matching nothing instead of
     * quietly becoming a rule about nothing at all (an empty `whereIn` and an
     * absent condition are very different queries).
     *
     * @param  int|iterable<int>  $ids
     * @return list<int>
     */
    public function descendants(int|iterable $ids): array
    {
        $roots = [];

        foreach (is_int($ids) ? [$ids] : $ids as $id) {
            $roots[(int) $id] = true;
        }

        if ($roots === []) {
            return [];
        }

        $key = implode(',', array_keys($roots));

        if (isset($this->subtrees[$key])) {
            return $this->subtrees[$key];
        }

        $children = $this->childMap();
        $collected = [];
        $queue = array_keys($roots);

        // Breadth-first with a seen-set rather than recursion: a parent chain
        // that loops is a corrupt row, not an impossible one, and a walk that
        // trusts the data hangs the request rather than showing a wrong page.
        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($collected[$id])) {
                continue;
            }

            $collected[$id] = true;

            foreach ($children[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $this->subtrees[$key] = array_map('intval', array_keys($collected));
    }

    /**
     * This category and everything ABOVE it, innermost first — the chain a
     * scope walks when it asks "does a rule up there govern me".
     *
     * @return list<int>
     */
    public function ancestors(int $id, bool $includeSelf = false): array
    {
        $chain = $includeSelf ? [$id] : [];
        $seen = [$id => true];
        $cursor = (int) ($this->find($id)?->parent_id ?? 0);

        while ($cursor && ! isset($seen[$cursor])) {
            $seen[$cursor] = true;
            $chain[] = $cursor;
            $cursor = (int) ($this->find($cursor)?->parent_id ?? 0);
        }

        return $chain;
    }

    /**
     * Every category in TREE order — a parent immediately followed by its own
     * children — ready for a picker to indent with {@see indent()}.
     *
     * This is what replaced `Category::orderBy('sort')->get()` on every screen
     * that offers categories. The order is the point: sorting the flat table by
     * `sort` interleaves the levels, so the list a shop owner reads bears no
     * relation to the tree they built.
     *
     * @param  list<int>  $exclude  a branch to leave out, children and all
     * @return Collection<int, Category>
     */
    public function ordered(bool $listedOnly = false, array $exclude = []): Collection
    {
        $rows = new Collection;
        $skip = array_flip($this->descendants(array_map('intval', $exclude)));

        $walk = function (int $parentId, int $depth) use (&$walk, $rows, $listedOnly, $skip) {
            foreach ($this->childMap()[$parentId] ?? [] as $id) {
                $category = $this->find($id);

                if (! $category || isset($skip[$id]) || ($listedOnly && ! $category->isListed())) {
                    continue;
                }

                $this->depths[$id] = $depth;
                $rows->push($category);

                $walk($id, $depth + 1);
            }
        };

        $walk(0, 0);

        // A row whose parent is missing (a deleted category, a broken import)
        // would otherwise vanish from every picker in the shop while still
        // governing whatever points at it. Shown at the top level instead.
        $seen = $rows->pluck('id')->flip();

        foreach ($this->all() as $category) {
            if ($seen->has($category->id) || isset($skip[$category->id]) || ($listedOnly && ! $category->isListed())) {
                continue;
            }

            $this->depths[$category->id] = 0;
            $rows->push($category);
        }

        return $rows;
    }

    /**
     * The same list as a map for a `<select>`: id => name, indented.
     *
     * @return array<int, string>
     */
    public function options(bool $listedOnly = false, array $exclude = []): array
    {
        $options = [];

        foreach ($this->ordered($listedOnly, $exclude) as $category) {
            $options[$category->id] = $this->indent($category);
        }

        return $options;
    }

    /** How deep a category sits — 0 at the top. Known once ordered() has walked. */
    public function depth(int $id): int
    {
        if ($this->depths === []) {
            $this->ordered();
        }

        return $this->depths[$id] ?? 0;
    }

    /** The prefix a picker draws a nested category with — one place, so every screen indents alike. */
    public function indent(Category $category, string $marker = '— '): string
    {
        $name = $category->t('name', admin_content_locale()) ?: $category->slug;

        return str_repeat($marker, $this->depth((int) $category->id)).$name;
    }

    /** @return array<int, list<int>> */
    protected function childMap(): array
    {
        if ($this->children !== null) {
            return $this->children;
        }

        $map = [];

        foreach ($this->all() as $category) {
            $map[(int) $category->parent_id][] = (int) $category->id;
        }

        return $this->children = $map;
    }

    /** For the admin, which edits the tree and then reads it back in the same request. */
    public function flush(): void
    {
        $this->all = null;
        $this->children = null;
        $this->depths = [];
        $this->subtrees = [];
    }
}
