<?php

namespace Torgash\Search\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Search\Engine;
use Torgash\Search\History;
use Torgash\Search\Scope;
use Torgash\Search\Stats;

/**
 * The live window's contents, as HTML — asked while a customer types.
 *
 * The same engine as the results page answers (so the window and the page
 * cannot disagree), and every product in it is priced, pictured and labelled
 * by the catalogue's own code, the way a card is. What other modules want in
 * the window (information pages, …) arrives through `search.suggest.sections`
 * — this module names none of them.
 */
class SuggestController extends Controller
{
    public function __invoke(Request $request, Engine $engine, History $history)
    {
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 100));
        $min = max(1, (int) setting('torgash.search', 'min_chars'));

        if (mb_strlen($q) < $min) {
            $recent = $history->recent((int) setting('torgash.search', 'history_limit'));

            return view('torgash.search::suggest.recent', [
                'recent' => $recent,
                // What others search for, before anything is typed — minus
                // what this visitor already has in their own list.
                'popular' => array_values(array_diff(app(Stats::class)->popular(null, (int) setting('torgash.search', 'suggest_popular')), $recent)),
            ]);
        }

        $scope = Scope::current($request);
        $withCatalog = Scope::withCatalog($scope);
        // The site without the catalogue asks the catalogue nothing: an empty
        // answer carries the query's words to the other modules' sections.
        $result = $withCatalog ? $engine->search($q) : new \Torgash\Search\SearchResult(new \Torgash\Search\SearchQuery($q), []);
        $batch = max(1, (int) setting('torgash.search', 'suggest_products'));
        $offset = max(0, (int) $request->query('offset', 0));
        // Not a search yet — but a product opened from this window makes it one.
        app(Stats::class)->shown($result, array_slice($result->ids, $offset, $batch), $request);

        // The next batch of products, asked by the window when it is scrolled
        // to the end: the same list the first batch came from, further on.
        if ($offset > 0) {
            return view('torgash.search::suggest.products', $this->productsView($q, $result, $offset, $batch, $scope));
        }

        return view('torgash.search::suggest.results', $this->productsView($q, $result, 0, $batch, $scope) + [
            'categories' => $withCatalog && setting('torgash.search', 'suggest_categories') ? $this->categories($engine->categoriesOf($result->ids)) : collect(),
            'brands' => $withCatalog && setting('torgash.search', 'suggest_brands') ? $this->brands($result->query->normalized) : collect(),
            'sections' => $scope === Scope::SITE ? $this->sections($q, $result) : [],
            // The shop's phrases and popular searches are about the catalogue.
            'phrases' => $withCatalog ? $this->phrases($result) : [],
        ]);
    }

    /**
     * What another module wants in the window, when the whole site is being
     * searched: `[['title' => …, 'items' => [['label', 'url', 'hint'?], …]], …]`.
     *
     * @return list<array{title: string, items: list<array<string, string>>}>
     */
    public static function sections(string $q, \Torgash\Search\SearchResult $result): array
    {
        return array_values(array_filter((array) hook_filter('search.suggest.sections', [], [
            'q' => $q,
            'words' => $result->query->words,
        ]), fn ($s) => ! empty($s['items'])));
    }

    /**
     * One batch of products and everything drawing them needs — the settings
     * of what a row shows, which of them are already in the basket, what to
     * mark in bold, and where the next batch is (null at the end, or when the
     * shop does not load more).
     *
     * @return array<string, mixed>
     */
    protected function productsView(string $q, \Torgash\Search\SearchResult $result, int $offset, int $batch, string $scope): array
    {
        $products = $this->products(array_slice($result->ids, $offset, $batch));
        $next = $offset + $batch;

        return [
            'q' => $q,
            'scope' => $scope,
            'result' => $result,
            'products' => $products,
            'show' => [
                'image' => (bool) setting('torgash.search', 'suggest_images'),
                'price' => (bool) setting('torgash.search', 'suggest_prices'),
                'sku' => (bool) setting('torgash.search', 'suggest_skus'),
                'stock' => (bool) setting('torgash.search', 'suggest_stock'),
                'buy' => (bool) setting('torgash.search', 'suggest_add_to_cart'),
                // What an add from here is answered with; "shop" leaves it to
                // the basket's own setting.
                'after_add' => in_array($after = (string) setting('torgash.search', 'suggest_after_add'), ['dialog', 'toast', 'nothing'], true) ? $after : null,
            ],
            'inCart' => $this->inCart(),
            // Bold what the answer was matched against: the corrected or
            // retyped query when it was read differently.
            'marked' => setting('torgash.search', 'suggest_highlight')
                ? ($result->readAs !== null ? (new \Torgash\Search\SearchQuery($result->readAs))->words : $result->query->words)
                : [],
            'more' => setting('torgash.search', 'suggest_load_more') && $next < $result->total()
                ? route('search.suggest', ['q' => $q, 'offset' => $next, 'scope' => $scope])
                : null,
        ];
    }

    /**
     * Products in the basket AS THEMSELVES — a line with no configuration,
     * whose key is the product id (Lines). A configured line is a different
     * thing from the product: "in cart" beside a phone would be untrue when
     * what is in the basket is that phone with a warranty the button did not
     * add.
     *
     * @return array<int, int> product id => quantity
     */
    protected function inCart(): array
    {
        return class_exists(\Torgash\Cart\ListBuy::class) ? app(\Torgash\Cart\ListBuy::class)->inCartMap() : [];
    }

    /**
     * The shop's own phrases first, then what people search for — both
     * beginning with what is being typed.
     *
     * @return list<string>
     */
    protected function phrases(\Torgash\Search\SearchResult $result): array
    {
        $pinned = app(\Torgash\Search\Rules::class)->suggest($result->query);
        $popular = app(Stats::class)->popular($result->query, (int) setting('torgash.search', 'suggest_popular'));

        return array_slice(array_values(array_unique([...$pinned, ...$popular])), 0, max(5, count($pinned)));
    }

    /** "Clear" under the recent searches — the visitor's own list, gone. */
    public function forget(History $history)
    {
        $history->forget();

        return view('torgash.search::suggest.recent', ['recent' => []]);
    }

    /** @param  list<int>  $ids */
    protected function products(array $ids)
    {
        if ($ids === []) {
            return collect();
        }

        $position = array_flip($ids);
        $products = Product::listed()->withCardData()->whereIn('m_catalog_products.id', $ids)->get()
            ->sortBy(fn (Product $p) => $position[$p->id] ?? PHP_INT_MAX)->values();

        // Announced like any grid, so every module that prices, labels or
        // stocks a card answers the whole set in one query.
        hook_action('catalog.products.listed', ['products' => $products]);

        return $products;
    }

    /** @param  array<int, int>  $counts */
    protected function categories(array $counts)
    {
        if ($counts === []) {
            return collect();
        }

        return Category::whereIn('id', array_keys($counts))->listed()->get()
            ->map(fn (Category $c) => ['category' => $c, 'count' => $counts[$c->id]])
            ->sortByDesc('count')->values();
    }

    /**
     * Brands whose name begins with a word of the query — a brand typed is a
     * brand page wanted, which is a better answer than its products one by one.
     */
    protected function brands(string $normalized)
    {
        if ($normalized === '') {
            return collect();
        }

        $like = addcslashes($normalized, '\\%_').'%';

        return DB::table('m_catalog_feature_values as v')
            ->join('m_catalog_features as f', 'f.id', '=', 'v.feature_id')
            ->where('f.purpose', 'brand')
            ->whereNotNull('v.slug')
            ->whereRaw('exists (select 1 from jsonb_each_text(v.value) t(k, val) where lower(t.val) like ?)', [$like])
            ->orderBy('v.sort')
            ->limit(4)
            ->get(['v.slug', 'v.value'])
            ->map(fn ($row) => [
                'slug' => $row->slug,
                'name' => ($names = (array) json_decode($row->value, true))[app()->getLocale()] ?? reset($names),
            ]);
    }
}
