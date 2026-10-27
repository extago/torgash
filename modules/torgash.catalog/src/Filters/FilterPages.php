<?php

namespace Torgash\Catalog\Filters;

use App\Core\Support\Currencies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\FilterPage;

/**
 * Which filter combinations this shop has decided are pages.
 *
 * Two questions, and they are the same lookup from opposite ends. The category
 * page asks "has the combination this visitor just ticked got an address of its
 * own?" — if it has, the listing canonicalises to it, which is the whole point
 * of the table: without that line the shop publishes the same set of products
 * at one indexable address and an unbounded number of unindexable ones. The
 * category page also asks "which of these should I link to", because an address
 * nothing links to is an address nothing crawls.
 *
 * A registered singleton, read once per request: a category page asks both
 * questions and the sidebar asks again — same reasoning as Support\Labels.
 */
class FilterPages
{
    /** @var array<string, array{filters: Collection<int, FilterPage>, places: Collection<int, FilterPage>}> */
    protected array $links = [];

    /** @var array<string, FilterPage|false> */
    protected array $byFingerprint = [];

    /**
     * The links a category draws above its grid, in two rows.
     *
     * Two rows because they are two different sentences: "Apple laptops" holds
     * FEWER products than the category and is a way of narrowing it, while
     * "Laptops in Kyiv" holds the same ones under different words. Mixing them
     * makes a strip where the first button leads inwards and the second one
     * sideways.
     *
     * Bounded, and bounded in SQL: a shop publishing a page per town has
     * hundreds of live addresses in one category, and a strip of hundreds of
     * buttons is not a menu — it is the reason a visitor scrolls past the whole
     * card. `in_list` is the editorial answer ("this address is published, but
     * the category does not advertise it"), the limit is the mechanical one.
     *
     * The two are not the same exception. A page the shop took OUT of the list
     * is never drawn — not even on its own address, where it is only a blue
     * "you are here" and the way back is the breadcrumb and the filter box that
     * is ticked in the panel. A page that is in the list and merely did not FIT
     * is drawn while it is the one being looked at, because it belongs in the
     * row and the strip is where its neighbours are.
     *
     * @return array{filters: Collection<int, FilterPage>, places: Collection<int, FilterPage>}
     */
    public function linksFor(Category $category, ?FilterPage $current = null): array
    {
        $key = $category->id.':'.($current?->id ?? 0);

        if (isset($this->links[$key])) {
            return $this->links[$key];
        }

        $limit = max(0, (int) setting('torgash.catalog', 'filter_page_links', 12));

        $group = function (bool $geo) use ($category, $current, $limit): Collection {
            $rows = FilterPage::enabled()
                ->where('in_list', true)
                ->where('category_id', $category->id)
                ->when($geo,
                    fn ($q) => $q->whereNotNull('place_id'),
                    fn ($q) => $q->whereNull('place_id'))
                ->orderBy('position')->orderBy('id')
                // One spare: the page being looked at may not be among them,
                // and prepending it must not push the row over the shop's limit.
                ->when($limit > 0, fn ($q) => $q->limit($limit + 1))
                ->get();

            // To the front, whether it was fetched or not: the row is cut to
            // the limit below, and a page that came back in the window but
            // sits past the cut would be dropped from under the visitor
            // standing on it.
            if ($current && $current->in_list && $current->isGeo() === $geo) {
                $rows = $rows->reject(fn (FilterPage $p) => $p->id === $current->id)
                    ->prepend($current);
            }

            return $limit > 0 ? $rows->take($limit)->values() : $rows->values();
        };

        $links = ['filters' => $group(false), 'places' => $group(true)];
        $pages = $links['filters']->concat($links['places']);

        // Both of the things every link in the list asks for, answered once:
        // the category it builds its address from (they all share this one) and
        // the words it is named after.
        $pages->each(fn (FilterPage $page) => $page->setRelation('category', $category));
        FilterPage::primeValues($pages);

        // ...and the third thing: a city page's link text is the town's name,
        // and a category with twenty of them would otherwise be twenty
        // lookups. Announced, not fetched — the towns belong to another module
        // — which is the `catalog.products.listed` idiom applied to places.
        if ($placeIds = $pages->pluck('place_id')->filter()->unique()->values()->all()) {
            hook_action('locations.places.listed', ['ids' => $placeIds]);
        }

        return $this->links[$key] = $links;
    }

    /**
     * The page that stands for exactly this set of values, or null.
     *
     * Exactly: a visitor who has ticked Apple AND 16GB is not on the "Apple"
     * page, and canonicalising them onto it would tell a search engine that a
     * narrower set of products is the same page as a wider one. The price
     * bounds join the same exact match (PLAN §30.14) for the same reason.
     */
    public function match(Category $category, array $values, array $flags = [], ?int $priceMin = null, ?int $priceMax = null): ?FilterPage
    {
        $fingerprint = FilterPage::fingerprintFor((int) $category->id, $values, null, $flags, $priceMin, $priceMax);

        if (! isset($this->byFingerprint[$fingerprint])) {
            $this->byFingerprint[$fingerprint] = FilterPage::enabled()
                ->where('fingerprint', $fingerprint)->first() ?: false;
        }

        return $this->byFingerprint[$fingerprint] ?: null;
    }

    /**
     * The page a FILTERED LISTING should point its canonical at, if any.
     *
     * The question is matched WHOLE: the characteristic values, the yes/no
     * flags AND the price bounds in force have to be exactly what some
     * published page stands for. Each of those is part of an address now, so
     * none of them is a reason to refuse a canonical on its own — a visitor
     * ticking "in stock" or typing "up to 30 000" may simply be standing on a
     * page that says so, asked of the fingerprint rather than special-cased
     * here (§107's argument, extended to price).
     *
     * A price range that matches NO published page still refuses, and needs no
     * extra code to do it: such a fingerprint matches nothing in
     * {@see match()}, exactly as an arbitrary combination of characteristic
     * values already did before this — the visitor holds FEWER products than
     * any page, and pointing them at one that shows more would be the one
     * genuinely misleading answer.
     */
    public function canonicalFor(?Category $category, Request $request): ?FilterPage
    {
        if (! $category) {
            return null;
        }

        $values = self::selected($request);
        $flags = app(Flags::class)->active($request);
        [$priceMin, $priceMax] = self::priceBounds($request);

        // Nothing ticked at all is the category's own address, not a page.
        if ($values === [] && $flags === [] && $priceMin === null && $priceMax === null) {
            return null;
        }

        return $this->match($category, $values, $flags, $priceMin, $priceMax);
    }

    /**
     * The characteristic filters on a request, canonical.
     *
     * @return array<int, list<int>>
     */
    public static function selected(Request $request): array
    {
        // One parser for both spellings — `?material=steel` and
        // `?feature[8][]=22` are the same question, and a second reading of it
        // here would let the canonical and the grid disagree about what the
        // visitor asked for.
        return app(FilterQuery::class)->parse($request);
    }

    /**
     * `?price_min=`/`?price_max=` read the way the panel's own boxes write
     * them — typed in major units — converted to the minor units of the
     * shop's books, which every price column and every published page's own
     * bounds are in.
     *
     * Typed in the shop's currency unless `?price_currency=` says otherwise:
     * the panel shows the visitor's currency (a shopper reading roubles types
     * roubles), and names it beside the numbers so the address means the same
     * thing whoever opens it — "under 10 000" alone would be dollars to one
     * visitor and roubles to the next. Converted by the currency's rate to
     * the books (amount = books × rate), the lower bound rounded down and the
     * upper one up, so a bound typed in another currency never shuts out the
     * very product whose price was read off the card. An unknown currency is
     * read as the shop's own — a broken word is not a reason to filter by
     * nothing.
     *
     * @return array{0: int|null, 1: int|null}
     */
    public static function priceBounds(Request $request): array
    {
        $min = $request->filled('price_min') ? FilterPage::minorAmount($request->input('price_min')) : null;
        $max = $request->filled('price_max') ? FilterPage::minorAmount($request->input('price_max')) : null;
        $rate = self::typedRate($request);

        if ($rate !== 1.0) {
            $min = $min === null ? null : (int) floor($min / $rate);
            $max = $max === null ? null : (int) ceil($max / $rate);
        }

        return [$min, $max];
    }

    /** The rate of the currency `?price_currency=` names, or 1 for the books. */
    public static function typedRate(Request $request): float
    {
        $code = strtoupper(trim((string) $request->query('price_currency', '')));

        if ($code === '' || $code === shop_currency()) {
            return 1.0;
        }

        $rate = (float) (app(Currencies::class)->find($code)?->rate ?? 0);

        return $rate > 0 ? $rate : 1.0;
    }

    /**
     * The one comparison "under 30 000" means — shared by the sidebar's own
     * boxes ({@see \Torgash\Catalog\Http\StorefrontController::applyFilters()})
     * and a published page's address ({@see FilterPage::applyTo()}), so the two
     * can never drift into meaning different things about the same words.
     */
    public static function applyPriceBounds(Builder $query, ?int $min, ?int $max): Builder
    {
        if ($min !== null) {
            $query->where('price', '>=', $min);
        }

        if ($max !== null) {
            $query->where('price', '<=', $max);
        }

        return $query;
    }

    /** For the admin, which edits pages and then renders a storefront preview of them. */
    public function flush(): void
    {
        $this->links = [];
        $this->byFingerprint = [];
    }
}
