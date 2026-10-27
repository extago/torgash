<?php

namespace Torgash\Menus;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Stock\AvailabilityStatuses;

/**
 * How many products each category's WHOLE SUBTREE holds for this visitor —
 * the number behind "show a subcategory only when something in it can be
 * bought" and behind the counter beside a menu item (PLAN §39.4).
 *
 * ONE query for every category at once, never one per menu item: a recursive
 * walk pairs each category with everything beneath it (UNION, not UNION ALL,
 * so a looping parent_id ends instead of hanging the request), joined to the
 * products this visitor may be SHOWN — `Product::listed()`, which already
 * carries whatever a module narrows it by (the chosen pickup point's
 * assortment). Counted DISTINCT per ancestor, so a product filed in two
 * subcategories of one branch is one product of that branch.
 *
 * Three numbers, one per rule a menu can ask:
 *   listed    — anything shown at all;
 *   orderable — can be bought now (`applyOrderable()`: a preorder counts,
 *               sold out and discontinued do not) — the catalogue's own
 *               "in stock only" box, so the menu and the box cannot disagree;
 *   in_stock  — orderable AND on a shelf: a preorder with nothing left does
 *               not count, an untracked status ("unlimited") does.
 *
 * Cached for `counts_ttl` minutes under the hash of the query itself, so a
 * visitor whose listing is narrowed differently gets a cache of their own
 * without this class knowing who narrows it or how. 0 turns the cache off.
 */
class CategoryCounts
{
    /** @var array<int, array{listed: int, orderable: int, in_stock: int}>|null */
    protected ?array $counts = null;

    /** Off for the admin's preview: it explains the menu as of NOW, not ten minutes ago. */
    protected bool $cached = true;

    public const RULES = ['always', 'listed', 'orderable', 'in_stock'];

    public function fresh(): static
    {
        $this->cached = false;
        $this->counts = null;

        return $this;
    }

    public function of(int $categoryId, string $rule): int
    {
        $row = $this->all()[$categoryId] ?? null;

        return match ($rule) {
            'orderable' => $row['orderable'] ?? 0,
            'in_stock' => $row['in_stock'] ?? 0,
            default => $row['listed'] ?? 0,
        };
    }

    /** @return array<int, array{listed: int, orderable: int, in_stock: int}> */
    public function all(): array
    {
        if ($this->counts !== null) {
            return $this->counts;
        }

        $query = $this->query();
        $ttl = (int) setting('torgash.menus', 'counts_ttl', 10);

        if ($ttl <= 0 || ! $this->cached) {
            return $this->counts = $this->run($query);
        }

        $key = 'menus.category-counts.'.sha1($query->toSql().'|'.serialize($query->getBindings()));

        return $this->counts = Cache::remember($key, now()->addMinutes($ttl), fn () => $this->run($query));
    }

    protected function query(): \Illuminate\Database\Query\Builder
    {
        $statuses = app(AvailabilityStatuses::class);
        $ids = fn () => Product::listed()->select('m_catalog_products.id');

        $orderable = $statuses->applyOrderable($ids());
        $inStock = $statuses->applyOrderable($ids())->where(function (Builder $q) use ($statuses) {
            $untracked = array_keys(array_filter($statuses->all(), fn (array $s) => ! $s['tracks_stock']));
            $q->where('stock', '>', 0);

            if ($untracked !== []) {
                $q->orWhereIn('availability_status', $untracked);
            }
        });

        $tree = 'with recursive tree(ancestor, id) as ('
            .'select id, id from m_catalog_categories where deleted_at is null '
            .'union select tree.ancestor, c.id from tree join m_catalog_categories c on c.parent_id = tree.id and c.deleted_at is null'
            .') select ancestor, id from tree';

        return DB::query()
            ->fromRaw('('.$tree.') as t')
            ->join('m_catalog_category_product as cp', 'cp.category_id', '=', 't.id')
            ->joinSub($ids(), 'l', 'l.id', '=', 'cp.product_id')
            ->leftJoinSub($orderable, 'o', 'o.id', '=', 'cp.product_id')
            ->leftJoinSub($inStock, 's', 's.id', '=', 'cp.product_id')
            ->groupBy('t.ancestor')
            ->select('t.ancestor')
            ->selectRaw('count(distinct cp.product_id) as listed, count(distinct o.id) as orderable, count(distinct s.id) as in_stock');
    }

    /** @return array<int, array{listed: int, orderable: int, in_stock: int}> */
    protected function run(\Illuminate\Database\Query\Builder $query): array
    {
        $out = [];

        foreach ($query->get() as $row) {
            $out[(int) $row->ancestor] = [
                'listed' => (int) $row->listed,
                'orderable' => (int) $row->orderable,
                'in_stock' => (int) $row->in_stock,
            ];
        }

        return $out;
    }

    public function flush(): void
    {
        $this->counts = null;
    }
}
