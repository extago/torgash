<?php

namespace Torgash\Search;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Stock\AvailabilityStatuses;

/**
 * The one place a search is answered — the results page and (next) the live
 * window ask the same thing, so they cannot disagree.
 *
 * Sources of candidates are a registry (`search.retrievers`): each answers on
 * its own scale, the engine normalises every one to its best result and adds
 * them up by weight. `fallback` sources are asked only when the others found
 * nothing. The order in which a query is tried:
 *
 *   1. as typed, by every primary source;
 *   2. retyped on the other keyboard layout ("ytyneb" → "ноутбук");
 *   3. with its unknown words corrected by the catalogue's own dictionary
 *      (`Speller`: "ноутбк" → "ноутбук") — as typed only: a layout reading
 *      of gibberish is more gibberish, and correcting it finds random words;
 *   4. by the fallback sources, if a module registered any — as typed.
 *
 * Whenever the answer is to a different question than the one typed, the
 * result says which (`readAs`, `how`). And when the query DID find something
 * but a word of it is unknown and its correction finds more, the result
 * carries that correction as `didYouMean` — offered, never substituted.
 *
 * What a visitor may be shown at all is `Product::listed()` (the pickup
 * point's assortment and the rest), handed to every source as a subquery so a
 * limit is never spent on products that would be thrown away. The final order
 * honours the catalogue's own tumbler — sold out last — exactly as a listing
 * does.
 */
class Engine
{
    public const LIMIT = 500;

    /**
     * @param  ?int  $categoryId  narrow to this category — and everything under
     *     it, since a category IS its subcategories (CategoryTree)
     */
    public function search(string $raw, ?int $categoryId = null): SearchResult
    {
        $result = $this->find($raw);

        if ($categoryId === null || $result->ids === []) {
            return $result;
        }

        $inside = DB::table('m_catalog_category_product')
            ->whereIn('category_id', app(CategoryTree::class)->descendants($categoryId))
            ->whereIn('product_id', $result->ids)
            ->distinct()
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        return new SearchResult(
            $result->query,
            array_values(array_filter($result->ids, fn ($id) => isset($inside[$id]))),
            $result->readAs,
            $result->how,
            $result->didYouMean,
        );
    }

    /**
     * Where what was found lives: each category holding at least one of the
     * products, most first — "narrow to Laptops (3)".
     *
     * @param  list<int>  $ids
     * @return array<int, int> category id => how many of them
     */
    public function categoriesOf(array $ids, int $limit = 6): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('m_catalog_category_product')
            ->whereIn('product_id', $ids)
            ->groupBy('category_id')
            ->selectRaw('category_id, count(distinct product_id) as n')
            ->orderByDesc('n')
            ->orderBy('category_id')
            ->limit($limit)
            ->pluck('n', 'category_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    protected function find(string $raw): SearchResult
    {
        $query = new SearchQuery($raw);

        if ($query->isEmpty()) {
            return new SearchResult($query, []);
        }

        $scores = $this->run($query, fallback: false);

        if ($scores !== []) {
            return new SearchResult($query, $this->order($scores), didYouMean: $this->betterSpelling($query, count($scores)));
        }

        $alternatives = Layout::alternatives($query->normalized);

        foreach ($alternatives as $retyped) {
            $scores = $this->run(new SearchQuery($retyped), fallback: false);

            if ($scores !== []) {
                return new SearchResult($query, $this->order($scores), $retyped, 'layout');
            }
        }

        $corrected = app(Speller::class)->correct($query);
        $scores = $corrected === null ? [] : $this->run(new SearchQuery($corrected), fallback: false);

        if ($scores !== []) {
            return new SearchResult($query, $this->order($scores), $corrected, 'typo');
        }

        $scores = $this->run($query, fallback: true);

        return new SearchResult($query, $this->order($scores), null, $scores === [] ? null : 'fallback');
    }

    /**
     * The query found `$found` products, but a word of it is unknown to the
     * catalogue — "чехол айфн" finds the one case mentioning "айфн" in its
     * description. Worth offering the corrected query only when it finds MORE:
     * a correction that finds the same or fewer is not a better question.
     */
    protected function betterSpelling(SearchQuery $query, int $found): ?string
    {
        $corrected = app(Speller::class)->correct($query);

        if ($corrected === null || $corrected === $query->normalized) {
            return null;
        }

        return count($this->run(new SearchQuery($corrected), fallback: false)) > $found ? $corrected : null;
    }

    /**
     * The registered sources: key => ['class' => Retriever class, 'weight' =>
     * float, 'fallback' => bool]. Asked lazily — provider boot order is not
     * ours to depend on.
     *
     * @return array<string, array{class: class-string<Retriever>, weight: float, fallback: bool}>
     */
    public function retrievers(): array
    {
        $out = [];

        foreach ((array) hook_filter('search.retrievers', [], []) as $key => $definition) {
            if (! isset($definition['class']) || ! is_a($definition['class'], Retriever::class, true)) {
                continue;
            }

            $out[$key] = [
                'class' => $definition['class'],
                'weight' => (float) ($definition['weight'] ?? 1.0),
                'fallback' => (bool) ($definition['fallback'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Every reading of the query (as typed, and each synonym's rewrite —
     * Rules::readings()) through every source of the pass, each product
     * keeping its best; then whatever a shop's exclusion forbids for it.
     *
     * @return array<int, float> product id => combined score
     */
    protected function run(SearchQuery $query, bool $fallback): array
    {
        $rules = app(Rules::class);
        $best = [];

        foreach ($rules->readings($query) as [$reading, $factor]) {
            foreach ($this->runOnce($reading, $fallback) as $id => $score) {
                $best[$id] = max($best[$id] ?? 0, $score * $factor);
            }
        }

        return array_diff_key($best, $rules->excluded($query, array_keys($best)));
    }

    /** @return array<int, float> product id => combined score */
    protected function runOnce(SearchQuery $query, bool $fallback): array
    {
        $total = [];
        $listed = $this->listed();

        foreach ($this->retrievers() as $definition) {
            if ($definition['fallback'] !== $fallback) {
                continue;
            }

            $scores = app($definition['class'])->retrieve($query, clone $listed, self::LIMIT);
            $best = $scores === [] ? 0 : max($scores);

            if ($best <= 0) {
                continue;
            }

            foreach ($scores as $id => $score) {
                $total[(int) $id] = ($total[(int) $id] ?? 0) + $definition['weight'] * $score / $best;
            }
        }

        return $total;
    }

    /**
     * Best first, ties by id; then, if the shop puts sold-out goods last, the
     * same availability rank a listing applies — in SQL, over the ids in
     * score order, so the two keys combine exactly as they do in a category.
     *
     * @param  array<int, float>  $scores
     * @return list<int>
     */
    protected function order(array $scores): array
    {
        if ($scores === []) {
            return [];
        }

        uksort($scores, fn ($a, $b) => [$scores[$b], $a] <=> [$scores[$a], $b]);
        $ids = array_keys($scores);

        if (! setting('torgash.catalog', 'out_of_stock_last', false)) {
            return $ids;
        }

        $query = Product::query()->whereIn('id', $ids)->select('id');
        app(AvailabilityStatuses::class)->applyRank($query);

        return $query->orderByRaw('array_position(?::bigint[], m_catalog_products.id)', ['{'.implode(',', $ids).'}'])
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Which products this visitor may be shown — as a subquery of ids. */
    protected function listed(): Builder
    {
        return Product::listed()->select('m_catalog_products.id')->toBase();
    }
}
