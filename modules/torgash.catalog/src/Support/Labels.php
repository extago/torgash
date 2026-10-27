<?php

namespace Torgash\Catalog\Support;

use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Label;

/**
 * The label table, read once per request.
 *
 * Label::forProducts() re-read the whole table on every call, and a page calls
 * it once per product grid — the homepage has three (one per tab), so three
 * identical `select * from m_catalog_labels order by sort` for a table that is
 * a handful of rows and cannot change mid-request.
 *
 * isActiveNow() is applied here rather than by each caller: it depends on
 * now(), and a request is short enough that a label starting or ending
 * halfway through one is not a distinction worth a query.
 *
 * A registered singleton and not a `static` on the model, so the memo dies
 * with the request even under a long-lived worker — same reasoning as
 * App\Core\Support\Currencies.
 */
class Labels
{
    /** @var Collection<int, Label>|null */
    protected ?Collection $active = null;

    /** @var array<int, array<int, bool>> label id => product id => matches its rules */
    protected array $matches = [];

    /** @var array<int, list<int>>|null product id => manually assigned label ids, for the products asked so far */
    protected array $manual = [];

    /** Active-right-now labels, in admin sort order. @return Collection<int, Label> */
    public function active(): Collection
    {
        return $this->active ??= Label::orderBy('sort')->get()->filter->isActiveNow()->values();
    }

    /**
     * Which of $ids each rule-bearing label matches — asking the database
     * only about products this request has not asked about yet, and about
     * all the labels at once. A home page draws the same products in several
     * blocks and tabs, and each grid used to run every label's query again
     * (audit 2026-10-15: 32 queries).
     *
     * @param  list<int>  $labelIds
     * @param  list<int>  $ids
     * @param  callable(list<int>): array<int, list<int>>  $query  label id => the ids among the given that it matches
     * @return array<int, list<int>> label id => matching ids among $ids
     */
    public function matchedAll(array $labelIds, array $ids, callable $query): array
    {
        $unknown = array_values(array_filter($ids, function (int $id) use ($labelIds) {
            foreach ($labelIds as $labelId) {
                if (! isset($this->matches[$labelId][$id])) {
                    return true;
                }
            }

            return false;
        }));

        if ($unknown !== []) {
            $hits = $query($unknown);
            foreach ($labelIds as $labelId) {
                $yes = array_flip($hits[$labelId] ?? []);
                foreach ($unknown as $id) {
                    $this->matches[$labelId][$id] = isset($yes[$id]);
                }
            }
        }

        $out = [];
        foreach ($labelIds as $labelId) {
            $out[$labelId] = array_values(array_filter($ids, fn (int $id) => $this->matches[$labelId][$id] ?? false));
        }

        return $out;
    }

    /**
     * Manually assigned labels per product, read once per product a request.
     *
     * @param  list<int>  $ids
     * @param  callable(list<int>): array<int, list<int>>  $query  product id => label ids, for the given
     * @return array<int, list<int>>
     */
    public function manual(array $ids, callable $query): array
    {
        $unknown = array_values(array_diff($ids, array_keys($this->manual)));

        if ($unknown !== []) {
            $found = $query($unknown);
            foreach ($unknown as $id) {
                $this->manual[$id] = $found[$id] ?? [];
            }
        }

        return array_intersect_key($this->manual, array_flip($ids));
    }

    public function flush(): void
    {
        $this->active = null;
        $this->matches = [];
        $this->manual = [];
    }
}
