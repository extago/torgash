<?php

namespace Torgash\Catalog\Listing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Filters\FilterPages;
use Torgash\Catalog\Filters\FilterQuery;
use Torgash\Catalog\Filters\Flags;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Sorting\Sorts;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Catalog\Support\ListingView;

/**
 * A listing of products with the catalogue's panel beside it: what narrows
 * it (price, the yes/no flags, characteristics), the order it answers in, the
 * chips naming what is in force, and the count on the panel's button.
 *
 * Its SCOPE is whatever set of products the page is about — a category (and
 * everything under it), a sale's products, a search's answers, or all of
 * them. The panel then offers only what can narrow THAT set, measured against
 * it without the visitor's own selection (see narrowToWhatIsHere()). It lived
 * in the catalogue's controller, keyed on a category; a sale's page and the
 * search results are the same listing over a different set, and a second copy
 * of the panel's rules is how the two would drift apart.
 *
 * Stateless: one per request is as good as one for ever.
 */
class Listing
{
    /**
     * Everything a page drawing `torgash.catalog::partials.listing` needs for
     * $scope: one page of products (filtered, ordered, with card data), the
     * panel's options, the chips in force, the sort and the toolbar.
     *
     * $relevance is for an answer that has an order of its OWN — a search's,
     * best match first. It is offered as the first sort ("Relevance") and is
     * in force until the visitor picks another; it receives the query to
     * order. Without it the shop's sorts apply as on any listing.
     *
     * @param  (\Closure(Builder): mixed)|null  $relevance
     * @return array{products: \Illuminate\Contracts\Pagination\LengthAwarePaginator, filters: array, activeFilters: array, sort: ?array, listing: array}
     */
    public function page(Category|Builder|null $scope, Request $request, ?\Closure $relevance = null): array
    {
        $query = $this->applyFilters($this->narrowToCategory($this->productsQuery($scope), $scope, $request), $request);
        $sort = $this->sortOptions($request);

        if ($relevance) {
            $sorts = app(Sorts::class);
            $picked = (string) $request->query('sort', '');
            $byRelevance = ! in_array($picked, $sorts->offered(), true);

            if ($byRelevance) {
                $relevance($query);
                // Ties broken the way every listing breaks them (Sorts::apply()).
                $query->orderBy('m_catalog_products.id');
            } else {
                $this->sorted($query, $request);
            }

            $options = ['relevance' => __('Relevance')];
            $icons = ['relevance' => 'sparkles'];
            foreach ($sorts->offered() as $key) {
                $options[$key] = $sorts->label($key);
                $icons[$key] = $sorts->icon($key);
            }
            $sort = ['options' => $options, 'icons' => $icons, 'current' => $byRelevance ? 'relevance' : $picked];
        } else {
            $this->sorted($query, $request);
        }

        $filters = $this->filterOptions($scope, $request);
        $activeFilters = $this->activeFilters($request);

        if ($scope instanceof Builder) {
            $filters['in'] = $this->scopeCategories($scope, $request);
            $filters['in_clear'] = $this->urlWith($request, array_diff_key($request->query(), ['in' => true]));
            $picked = collect($filters['in'])->firstWhere('current', true);

            if ($picked) {
                array_unshift($activeFilters, ['label' => __('Category').': '.$picked['name'], 'url' => $filters['in_clear']]);
            }
        }

        return [
            'products' => within_pages($query->withCardData()->paginate(app(ListingView::class)->perPage($request))->withQueryString()),
            'filters' => $filters,
            'activeFilters' => $activeFilters,
            'sort' => $sort,
            'listing' => $this->listingOptions($request),
        ];
    }

    /** The panel's "Show 12 products" answer for $scope, as the listing would count it. */
    public function count(Category|Builder|null $scope, Request $request)
    {
        return $this->countAnswer($this->applyFilters($this->narrowToCategory($this->productsQuery($scope), $scope, $request), $request));
    }

    /**
     * A set that is not a category (a sale, a search) narrowed to one of the
     * categories its products stand in — `?in=<slug>`, the word the search
     * already used for "in Laptops" — that category AND everything under it,
     * as a category page counts. A category's own page has no use for it.
     */
    public function narrowToCategory(Builder $query, Category|Builder|null $scope, Request $request): Builder
    {
        $slug = trim((string) $request->query('in', ''));

        if (! $scope instanceof Builder || $slug === '') {
            return $query;
        }

        $category = Category::where('slug', $slug)->first();

        // A slug nobody knows narrows to NOTHING, never to everything: the
        // grid then says "nothing" and the chip names what was asked — the
        // rule an unknown characteristic code follows (FilterQuery).
        return $query->whereHas('categories', fn ($q) => $q->whereIn(
            'm_catalog_categories.id',
            $category ? app(CategoryTree::class)->descendants((int) $category->id) : [0],
        ));
    }

    /**
     * The categories a set's products stand in, most products first, for the
     * panel's "Category" block: name, link, how many, whether it is the one
     * picked. Counted against the set WITHOUT the visitor's other filters,
     * for the reason the characteristics are (narrowToWhatIsHere()). One
     * query; a product in two categories is counted in each.
     *
     * @return list<array{name: string, url: string, count: int, current: bool, slug: string}>
     */
    public function scopeCategories(Builder $scope, Request $request): array
    {
        // Which category each product stands in, for the set.
        $pairs = DB::table('m_catalog_category_product')
            ->whereIn('product_id', (clone $scope)->select('m_catalog_products.id'))
            ->get(['category_id', 'product_id']);

        if ($pairs->isEmpty()) {
            return [];
        }

        // A category's number is its SUBTREE's, as picking it narrows (and as
        // a category page counts): "Servers" holds the server parts under it.
        $tree = app(CategoryTree::class);
        $byCategory = $pairs->groupBy('category_id')->map(fn ($rows) => $rows->pluck('product_id')->all());
        $rows = $byCategory->keys()->mapWithKeys(function ($categoryId) use ($tree, $byCategory) {
            $products = [];
            foreach ($tree->descendants((int) $categoryId) as $id) {
                foreach ($byCategory[$id] ?? [] as $productId) {
                    $products[$productId] = true;
                }
            }

            return [$categoryId => count($products)];
        });

        $picked = trim((string) $request->query('in', ''));
        $rest = array_diff_key($request->query(), ['in' => true]);

        return Category::whereIn('id', $rows->keys())->listed()->get()
            ->map(fn (Category $category) => [
                'name' => (string) $category->t('name'),
                'slug' => (string) $category->slug,
                'count' => (int) $rows[$category->id],
                'current' => $category->slug === $picked,
                'url' => $this->urlWith($request, $rest + ['in' => $category->slug]),
            ])
            ->sortBy([['count', 'desc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * The set a listing is about, as a fresh query each time it is asked
     * (the callers add to it): a category and everything under it, a query a
     * page built for itself (a sale's products, a search's answers — handed
     * over as `listed()` products, and CLONED here, never added to), or the
     * whole catalogue for null.
     *
     * Visible products, optionally scoped to a category — a plain Product
     * query (whereHas, not the BelongsToMany relation directly) so it stays
     * a bare Eloquent Builder: selectRaw()/clone() for the price-bounds
     * aggregate below misbehave on a relation query, which still carries
     * the pivot's joined/selected columns and trips Postgres's GROUP BY
     * check as soon as an aggregate is added alongside them.
     */
    public function productsQuery(Category|Builder|null $scope): Builder
    {
        if ($scope instanceof Builder) {
            return clone $scope;
        }

        $category = $scope;
        $query = Product::listed();

        if ($category) {
            // The category AND everything under it. A shop keeps its products
            // in the leaves, so a parent that asked only about itself showed an
            // empty grid — which is what "Electronics" did here until this line
            // (Support\CategoryTree states the rule and why it has no switch).
            $query->whereHas('categories', fn ($q) => $q->whereIn(
                'm_catalog_categories.id',
                app(CategoryTree::class)->descendants((int) $category->id),
            ));
        }

        return $query;
    }

    public function applyFilters(Builder $query, Request $request): Builder
    {
        // The one comparison "under 30 000" means — shared with a published
        // filter page's own price range (PLAN §30.14), so the two can never
        // silently disagree about what the words on the box mean.
        [$priceMin, $priceMax] = FilterPages::priceBounds($request);
        FilterPages::applyPriceBounds($query, $priceMin, $priceMax);

        // "In stock", "on sale", and whatever a module registers beside them —
        // see Filters\Flags. The stock one used to be written out here, which
        // is the same missing idea as its box being written into the blade.
        app(Flags::class)->apply($query, $request);

        // Both spellings, one answer — see Filters\FilterQuery.
        foreach (FilterPages::selected($request) as $featureId => $valueIds) {
            $query->whereHas('featureAssignments', fn ($q) => $q
                ->where('feature_id', $featureId)
                ->whereIn('feature_value_id', $valueIds));
        }

        return $query;
    }

    /**
     * The order the listing answers in — the registry's, never a `->latest()`
     * written out here.
     *
     * All three listings called that in their own hand, so `?sort=` did
     * nothing anywhere and the word was reserved for a feature that did not
     * exist yet (PLAN §31.1). One line each now, and a module registering a
     * sort reaches all three at once.
     */
    public function sorted(Builder $query, Request $request): Builder
    {
        return app(Sorts::class)->apply($query, $request);
    }

    /**
     * What the sort select draws: every offered order and the one in force.
     *
     * Absent below two — a select holding one entry is not a choice, and a
     * shop that offers exactly one order has said it wants that order, not a
     * control saying so.
     *
     * @return array{options: array<string, string>, current: string}|null
     */
    public function sortOptions(Request $request): ?array
    {
        $sorts = app(Sorts::class);
        $offered = $sorts->offered();

        if (count($offered) < 2) {
            return null;
        }

        $options = [];

        $icons = [];

        foreach ($offered as $key) {
            $options[$key] = $sorts->label($key);
            $icons[$key] = $sorts->icon($key);
        }

        return ['options' => $options, 'icons' => $icons, 'current' => $sorts->current($request)];
    }

    /**
     * What the listing's toolbar draws besides the sort: the page sizes on
     * offer and the one in force, and grid or list. See Support\ListingView.
     */
    public function listingOptions(Request $request): array
    {
        $view = app(ListingView::class);

        return [
            'per_page' => $view->perPage($request),
            'choices' => $view->choices(),
            'layout' => $view->layout($request),
        ];
    }

    /**
     * How many products the panel's boxes, as they stand, would leave — the
     * number on the floating "Show 12 products" button, asked for by the
     * panel's script before anything is applied (`X-Catalog-Count`).
     *
     * The same query the listing itself runs, minus the order and the page:
     * the button may not promise a number the page then disagrees with. A
     * phrase rather than a bare number, so the plural is the lang file's and
     * not the script's.
     */
    public function countAnswer(Builder $query)
    {
        $count = $query->count();

        return response($count === 0
            ? __('Nothing matches')
            : trans_choice('Show :count product|Show :count products', $count, ['count' => $count]))
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }

    public function filterOptions(Category|Builder|null $scope, Request $request): array
    {
        // Which characteristics the panel OFFERS — not which ones can be
        // filtered by. A characteristic left out of the panel is still a word
        // an address is written in (that is what a published filter page is
        // made of), and one in force is still named in the chip row, which is
        // the way back off it. See Features\Places.
        $featuresQuery = Feature::shownIn('filters')->orderBy('sort')->with('values');
        $category = $scope instanceof Category ? $scope : null;
        if ($category) {
            // A characteristic attached to "Laptops" filters "Gaming laptops"
            // too: the assignment is made on the branch, not on the leaf, and
            // this is the one place in the tree that is read UPWARDS rather
            // than down — the products come from below, the rules from above.
            $branch = app(CategoryTree::class)->ancestors((int) $category->id, includeSelf: true);

            $featuresQuery->where(fn ($q) => $q->whereDoesntHave('categories')
                ->orWhereHas('categories', fn ($cq) => $cq->whereIn('category_id', $branch)));
        }

        $bounds = $this->productsQuery($scope)->toBase()->selectRaw('min(price) as min_price, max(price) as max_price')->first();

        $features = $this->narrowToWhatIsHere($featuresQuery->get(), $scope);
        $query = app(FilterQuery::class);
        $params = [];

        // What each box is called in a readable address, handed to the view so
        // the form can build one. Absent for a characteristic or a value with
        // no code: that one keeps the id form, and the two mix in one address.
        foreach ($features as $feature) {
            if (! $param = $query->paramFor($feature)) {
                continue;
            }

            $params[$feature->id] = ['param' => $param, 'values' => []];

            foreach ($feature->values as $value) {
                if ($name = $query->valueParamFor($value, $feature)) {
                    $params[$feature->id]['values'][$value->id] = $name;
                }
            }
        }

        return [
            'features' => $features,
            'price_min' => $bounds?->min_price ?? 0,
            'price_max' => $bounds?->max_price ?? 0,
            'selected' => FilterPages::selected($request),
            'params' => $params,
            // The yes/no boxes, measured against this page for the same reason
            // the characteristics are — a control that cannot narrow anything
            // still reads as a promise.
            'flags' => app(Flags::class)->offeredFor($this->productsQuery($scope)),
            // Which of those boxes are ticked. A request answers for itself;
            // a filter page adds its own below, because a page's flag is in
            // force at that address and the box has to show it — the same rule
            // its characteristic values follow, and for the same reason:
            // unticking the box is the way off the page.
            'flags_on' => app(Flags::class)->active($request),
        ];
    }

    /**
     * Only the characteristics that actually narrow THIS listing, and only the
     * values something here carries.
     *
     * The shop reported "Оперативная память" and "Накопитель" on the Clothing
     * page, and it was right: a characteristic attached to no category applies
     * everywhere by design (that is how Brand and Colour work), and nothing
     * asked whether there was anything here to filter. So the panel offered a
     * choice between two kinds of memory for a jumper, and either of them led
     * to an empty grid.
     *
     * Measured against the category WITHOUT the visitor's current selection.
     * Narrowing the options by what is already ticked is the tempting version
     * and it is a trap: pick "Steel" and Steel becomes the only material on
     * offer, so there is no way to widen again without editing the address.
     *
     * One query, whatever the catalogue's size — {@see QueryBudgetTest}.
     *
     * @param  Collection<int, Feature>  $features
     * @return Collection<int, Feature>
     */
    public function narrowToWhatIsHere(Collection $features, Category|Builder|null $scope): Collection
    {
        if ($features->isEmpty()) {
            return $features;
        }

        $present = DB::table('m_catalog_product_features')
            ->whereIn('product_id', $this->productsQuery($scope)->select('m_catalog_products.id'))
            ->whereIn('feature_id', $features->pluck('id'))
            ->whereNotNull('feature_value_id')
            ->distinct()
            ->get(['feature_id', 'feature_value_id'])
            ->groupBy('feature_id')
            ->map(fn ($rows) => $rows->pluck('feature_value_id')->map('intval')->all());

        return $features
            ->filter(fn (Feature $feature) => isset($present[$feature->id]))
            ->each(fn (Feature $feature) => $feature->setRelation(
                'values',
                $feature->values->whereIn('id', $present[$feature->id])->values(),
            ))
            ->values();
    }

    /**
     * The filters actually in force, each with the address that drops it.
     *
     * This replaced a second copy of the whole filter panel. The pill row above
     * the grid used to carry its own checkboxes and its own price boxes, with
     * the same NAMES as the sidebar's, inside the same form — so every value was
     * submitted twice (`?price_min=&price_max=&feature[8][]=22&price_min=&price_max=`,
     * reported from the shop) and, far worse, unticking a box in one of the two
     * changed nothing, because the other copy was still ticked and still
     * submitted. One set of controls, and this row is LINKS: a chip cannot be
     * half of a form's state.
     *
     * A filter page's own values are not chips — they are the address, and the
     * way off them is the category, not a cross.
     *
     * @return list<array{label: string, url: string}>
     */
    public function activeFilters(Request $request): array
    {
        $chips = [];
        $query = app(FilterQuery::class);
        $selected = FilterPages::selected($request);

        // Everything that is NOT a characteristic — price, stock, anything a
        // module hung on the address — kept as it was found, whichever spelling
        // the characteristics came in.
        $rest = array_diff_key(
            $request->query(),
            ['feature' => true] + array_fill_keys(array_keys($query->features()), true),
        );

        $filterFeatures = collect($query->features())->keyBy('id');

        $values = $selected === []
            ? collect()
            : FeatureValue::with('feature')->whereIn('id', array_merge(...array_values($selected)))->get()->keyBy('id');

        if ($selected !== []) {
            foreach ($selected as $featureId => $valueIds) {
                foreach ($valueIds as $valueId) {
                    if (! $value = $values->get($valueId)) {
                        continue;
                    }

                    $without = $selected;
                    $without[$featureId] = array_values(array_diff($valueIds, [$valueId]));

                    if ($without[$featureId] === []) {
                        unset($without[$featureId]);
                    }

                    $chips[] = [
                        'label' => trim(($value->feature?->t('name') ?? '').': '.$value->display()),
                        'url' => $this->urlWith($request, $query->queryFor($without, $values) + $rest),
                    ];
                }
            }
        }

        // The characteristics, kept as they are, for the chips that drop
        // something else.
        $feature = $selected === [] ? [] : $query->queryFor($selected, $values);

        // A code nobody knows: the grid is empty and nothing on the page says
        // why. Named with what was actually written, so the way back is a click.
        foreach ($query->unknown($request) as $featureId => $codes) {
            $feature = $filterFeatures[$featureId] ?? null;

            $without = $selected;
            unset($without[$featureId]);

            $chips[] = [
                'label' => trim(($feature?->t('name') ?? '').': '.implode(', ', $codes)),
                'url' => $this->urlWith($request, $query->queryFor($without, $values) + $rest),
            ];
        }

        if ($request->filled('price_min') || $request->filled('price_max')) {
            $from = $request->input('price_min');
            $to = $request->input('price_max');

            // The numbers as typed, in the currency they were typed in.
            $typedIn = app(\App\Core\Support\Currencies::class)->find(
                strtoupper((string) $request->query('price_currency', '')) ?: shop_currency(),
            );

            $chips[] = [
                'label' => __('Price').': '.trim(($from !== null && $from !== '' ? $from : '…').' — '.($to !== null && $to !== '' ? $to : '…'))
                    .($typedIn ? ' '.$typedIn->symbol : ''),
                'url' => $this->urlWith($request, $feature + array_diff_key($rest, array_flip(['price_min', 'price_max', 'price_currency']))),
            ];
        }

        // A flag that is in force says so even where the panel does not draw it
        // — an address arrived at from outside, or one whose box has just been
        // hidden because nothing on this page matches it. Otherwise the grid is
        // empty and nothing on the screen says why, which is exactly the answer
        // §102 gives for a value code nobody knows.
        $flags = app(Flags::class);

        foreach ($flags->active($request) as $key) {
            $chips[] = [
                'label' => $flags->label($key),
                'url' => $this->urlWith($request, $feature + array_diff_key($rest, array_flip([$key]))),
            ];
        }

        return $chips;
    }

    /**
     * The current address with a different query on it.
     *
     * Empty values are dropped rather than carried: a GET form submits every box
     * it holds, so `price_min=` rides along on a page nobody typed a price on,
     * and `page` goes because removing a filter has to land on the first page of
     * what is left.
     *
     * @param  array<string, mixed>  $query
     */
    public function urlWith(Request $request, array $query): string
    {
        $query = array_filter(
            $query,
            fn ($value, $key) => $key !== 'page' && $value !== '' && $value !== [] && $value !== null,
            ARRAY_FILTER_USE_BOTH,
        );

        return $request->url().($query === [] ? '' : '?'.$this->queryString($query));
    }

    /**
     * A query string in the shape the FORM would have produced.
     *
     * `http_build_query()` numbers a list — `feature[8][0]=22` — which parses
     * back correctly but is a third spelling of an address that already has
     * two, and it is not what the checkboxes on the page emit. Written out
     * instead, so a chip's link and the form's own submit land on the same URL.
     *
     * @param  array<string, mixed>  $query
     */
    public function queryString(array $query): string
    {
        $parts = [];

        foreach ($query as $key => $value) {
            if (! is_array($value)) {
                $parts[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);

                continue;
            }

            foreach ($value as $subKey => $subValue) {
                $name = rawurlencode((string) $key).rawurlencode('[').rawurlencode((string) $subKey).rawurlencode(']');

                foreach (is_array($subValue) ? $subValue : [$subValue] as $item) {
                    $parts[] = $name
                        .(is_array($subValue) ? rawurlencode('[').rawurlencode(']') : '')
                        .'='.rawurlencode((string) $item);
                }
            }
        }

        return implode('&', $parts);
    }
}
