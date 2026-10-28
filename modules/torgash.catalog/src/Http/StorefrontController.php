<?php

namespace Torgash\Catalog\Http;

use App\Core\Content\Markdown;
use App\Core\Seo\RedirectManager;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Torgash\Catalog\Filters\FilterPages;
use Torgash\Catalog\Filters\FilterQuery;
use Torgash\Catalog\Filters\Flags;
use Torgash\Catalog\Listing\Listing;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\PriceType;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Seo\ProductFacts;
use Torgash\Catalog\Seo\ProductSchema;
use Torgash\Catalog\Seo\ProductText;
use Torgash\Catalog\Sorting\Sorts;
use Torgash\Catalog\Support\CategoryNav;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Catalog\Support\ListingView;

class StorefrontController extends Controller
{
    public function __construct(protected RedirectManager $redirects, protected Listing $listing) {}

    /** 404s for a genuinely missing slug; 301s if it used to point somewhere. */
    protected function redirectOr404(string $requestPath)
    {
        if ($target = $this->redirects->resolve($requestPath)) {
            return redirect($target, 301);
        }

        abort(404);
    }

    /** Narrows an already category-scoped product query by the sidebar's price/flag/feature-value filters. */
    /** Sidebar data: which characteristics are offered as controls here, their values, and the price range among this category's products. */
    /**
     * The catalogue's own navigation — whole tree or the visitor's branch, with
     * counts or without, as the shop chose (Support\CategoryNav).
     *
     * @return list<array{category: ?Category, kind: string, depth: int, open: bool, count: ?int}>
     */
    protected function categoryRows(?Category $current): array
    {
        return app(CategoryNav::class)->rows($current);
    }

    /**
     * The children of the category being shown — the block a visitor drills
     * down with — less those the shop keeps out of it (`shown_in_parent`):
     * the menus and the list beside the catalogue still have them.
     */
    protected function subcategories(?Category $category): Collection
    {
        if (! $category) {
            return collect();
        }

        $tree = app(CategoryTree::class);

        return $tree->ordered(listedOnly: true)
            ->filter(fn (Category $c) => (int) $c->parent_id === (int) $category->id && ($c->shown_in_parent ?? true))
            ->values();
    }

    /**
     * A closed product or category, to a visitor outside its group (PLAN
     * §46): the shop's `closed_assortment` — `hide`, not there at all, or
     * `notice`, its name (and a product's photo) and "sign in", with no price,
     * no way to buy and nothing for an index.
     */
    protected function closed(string $name, array $groups, ?Product $product)
    {
        $assortment = app(\Torgash\Catalog\Support\Assortment::class);

        abort_if($assortment->mode() === 'hide', 404);

        // "Sign in" brings them back here.
        if (! auth()->check()) {
            session()->put('url.intended', url()->current());
        }

        page_meta()->set('title', $name)->set('robots', 'noindex, nofollow');

        return response()->view('torgash.catalog::closed', [
            'name' => $name,
            'product' => $product,
            'who' => $assortment->whoFor($groups),
        ], 403);
    }

    public function home()
    {
        // The storefront's own words for its home page (Admin → Storefronts),
        // else its name — on its own either way: appending the name to itself
        // is how a home page ends up titled "Shop · Shop".
        $storefront = current_storefront();
        page_meta()->set('title', pick_translation($storefront?->home_title) ?? $storefront?->name)->withoutSuffix();

        if ($description = pick_translation($storefront?->home_description)) {
            page_meta()->set('description', $description);
        }

        return view('torgash.catalog::home');
    }

    public function search(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        if ($q === '') {
            return redirect()->route('catalog.index');
        }

        // A search module, when one is installed, answers with the ORDER of
        // the products (and how it read the query); this page draws them as
        // the catalogue's own listing — panel, sorts, chips — over that set.
        // The category ("in Laptops", from the live window) is the LISTING's
        // to narrow by, not the module's: the panel's Category block counts
        // the whole answer, so a visitor can step from one to another.
        $answer = hook_filter('catalog.search', null, [
            'q' => $q,
            'category' => '',
            'page' => (int) $request->query('page', 1),
        ]);

        if (is_array($answer) && ! empty($answer['redirect'])) {
            return redirect()->to((string) $answer['redirect']);
        }

        $ids = is_array($answer) && array_key_exists('ids', $answer)
            ? array_values(array_map('intval', (array) $answer['ids']))
            : $this->ownSearch($q);

        $scope = Product::listed()->whereIn('m_catalog_products.id', $ids === [] ? [0] : $ids);

        if ($request->hasHeader('X-Catalog-Count')) {
            return $this->listing->count($scope, $request);
        }

        // Best match first until the visitor picks another order.
        $listing = $this->listing->page($scope, $request, fn (Builder $query) => $query->orderByRaw(
            'array_position(?::bigint[], m_catalog_products.id)', ['{'.implode(',', $ids).'}'],
        ));

        // `q` is what this page IS, so it belongs in the canonical — the page
        // is out of the index anyway (catalog.search is a private route), but a
        // canonical pointing every search at a bare /search would be a lie.
        page_meta()->set('title', __('Search').': '.$q)->canonicalParams('q');
        breadcrumbs()->add(__('Search'));

        return view('torgash.catalog::search', [
            'q' => $q,
            'listingPage' => $listing,
            'readAs' => $answer['read_as'] ?? null,
            'readHow' => $answer['how'] ?? null,
            'didYouMean' => $answer['did_you_mean'] ?? null,
            // [['title', 'items' => [['label', 'url', 'hint'?]]]] — the rest of
            // the site, from whoever answered the search.
            'sections' => (array) ($answer['sections'] ?? []),
        ]);
    }

    /**
     * The catalogue's own search, for a shop without a search module: the
     * matching ids, best first.
     *
     * websearch_to_tsquery safely parses free-form user input (unlike
     * to_tsquery, which treats & | ! ( ) : as literal operators and will
     * throw on malformed input) and tokenizes it through the same 'simple'
     * pipeline used to build search_vector, so hyphenated SKUs like "ST-2001"
     * match consistently between index and query.
     *
     * Typo-tolerant fallback only when that found nothing: word_similarity
     * (not similarity!) matches the query against the best-fitting WORD
     * inside the name, so a short query isn't diluted by unrelated words in
     * a longer name ("lapotp" vs "Laptop Pro 14" scores ~0.43 with
     * word_similarity but ~0.17 with plain similarity — verified in psql).
     * Called as a plain function, so it won't use the trigram GIN index —
     * acceptable on the rare "found nothing" path.
     *
     * @return list<int>
     */
    protected function ownSearch(string $q): array
    {
        $ids = Product::listed()
            ->whereRaw("search_vector @@ websearch_to_tsquery('simple', ?)", [$q])
            ->orderByRaw("ts_rank(search_vector, websearch_to_tsquery('simple', ?)) DESC", [$q])
            ->limit(1000)
            ->pluck('m_catalog_products.id');

        if ($ids->isEmpty()) {
            // The name in every language the product has, not two named ones.
            $nameExpr = "coalesce((select string_agg(t.v, ' ') from jsonb_each_text(name) as t(k, v)), '')";
            $ids = Product::listed()
                ->whereRaw("word_similarity(?, {$nameExpr}) > 0.3", [$q])
                ->orderByRaw("word_similarity(?, {$nameExpr}) DESC", [$q])
                ->limit(1000)
                ->pluck('m_catalog_products.id');
        }

        return $ids->map('intval')->all();
    }

    public function index(Request $request)
    {
        if ($request->hasHeader('X-Catalog-Count')) {
            return $this->listing->countAnswer($this->listing->applyFilters($this->listing->productsQuery(null), $request));
        }

        $products = within_pages($this->listing->sorted($this->listing->applyFilters($this->listing->productsQuery(null), $request), $request)
            ->withCardData()->paginate(app(ListingView::class)->perPage($request))->withQueryString());

        // The heading and the title differ here on purpose, which is the
        // smallest example of why H1 lives in PageMeta at all.
        page_meta()->set(['title' => __('Catalog'), 'h1' => __('All products')]);
        breadcrumbs()->add(__('Catalog'));

        return view('torgash.catalog::index', [
            'category' => null,
            'categories' => $this->categoryRows(null),
            'subcategories' => collect(),
            'products' => $products,
            'filters' => $this->listing->filterOptions(null, $request),
            'activeFilters' => $this->listing->activeFilters($request),
            'filterPages' => ['filters' => collect(), 'places' => collect()],
            'filterPage' => null,
            'sort' => $this->listing->sortOptions($request),
            'listing' => $this->listing->listingOptions($request),
        ]);
    }

    public function category(string $slug, Request $request, FilterPages $filterPages)
    {
        $category = Category::where('slug', $slug)->reachable()->first();

        if (! $category) {
            return $this->redirectOr404("/catalog/{$slug}");
        }

        if (! app(\Torgash\Catalog\Support\Assortment::class)->categoryOpen($category)) {
            return $this->closed($category->t('name'), (array) app(\Torgash\Catalog\Support\Assortment::class)->closingGroupsForCategory($category), null);
        }

        if ($request->hasHeader('X-Catalog-Count')) {
            return $this->listing->countAnswer($this->listing->applyFilters($this->listing->productsQuery($category), $request));
        }

        $products = within_pages($this->listing->sorted($this->listing->applyFilters($this->listing->productsQuery($category), $request), $request)
            ->withCardData()->paginate(app(ListingView::class)->perPage($request))->withQueryString());

        $this->describeCategory($category, $products->first()?->primaryImageUrl(800));

        // The one line this whole table exists for. A visitor who has ticked a
        // combination the shop publishes as a page is looking at that page's
        // products under an address core will not canonicalise (it strips the
        // query string), so the listing says which address this set of products
        // really lives at. Nothing redirects: the filtered listing is a working
        // page for a person, it is only not a second entry in an index.
        if ($page = $filterPages->canonicalFor($category, $request)) {
            page_meta()->set('canonical', $page->url());
        }

        return view('torgash.catalog::index', [
            'category' => $category,
            'categories' => $this->categoryRows($category),
            'subcategories' => $this->subcategories($category),
            'products' => $products,
            'filters' => $this->listing->filterOptions($category, $request),
            'activeFilters' => $this->listing->activeFilters($request),
            'filterPages' => $filterPages->linksFor($category),
            'filterPage' => null,
            'sort' => $this->listing->sortOptions($request),
            'listing' => $this->listing->listingOptions($request),
        ]);
    }

    /**
     * The same listing, at the address the shop gave it.
     *
     * The page IS the filter, so its values are applied before anything the
     * visitor ticked — and what they tick on top stays a query parameter, which
     * core drops from the canonical. That is how one address can be both a
     * published page and a working filter panel.
     *
     * Empty is noindex, and worked out live rather than read off
     * `products_count`: the stored number is last night's, and a page that has
     * just sold out must not be advertised to a crawler this morning. It still
     * ANSWERS — an address that has been published does not get to 404 because
     * a supplier was late.
     */
    public function filterPage(string $category, string $filters, Request $request, FilterPages $filterPages)
    {
        $found = Category::where('slug', $category)->reachable()->first();

        $page = $found
            ? FilterPage::enabled()->where('category_id', $found->id)->where('slug', $filters)->first()
            : null;

        if (! $page) {
            return $this->redirectOr404("/catalog/{$category}/{$filters}");
        }

        $page->setRelation('category', $found);

        // A city page is a visitor saying where they are — they typed it into
        // a search engine and clicked it. Announced rather than written: the
        // catalogue has no business inside checkout's session, and the listener
        // there only takes the answer while the question is still unanswered,
        // so it can never overwrite a town the customer chose for themselves.
        // The same hook the pickup-point picker uses. A visit action: it is
        // said for every visit, the ones served from a kept copy too.
        if ($page->isGeo()) {
            visit_action('catalog.place_chosen', ['place_id' => (int) $page->place_id]);
        }

        $products = within_pages($this->listing->sorted($this->listing->applyFilters($page->applyTo($this->listing->productsQuery($found)), $request), $request)
            ->withCardData()->paginate(app(ListingView::class)->perPage($request))->withQueryString());

        $this->describeFilterPage($page, $products->total());

        // The sidebar shows this page's own values ticked, and its form still
        // posts to the plain category — so unticking one is a working way off
        // the page, which it would not be if the form pointed back at an
        // address that re-applies what was just removed.
        $options = $this->listing->filterOptions($found, $request);

        foreach ($page->valueSet() as $featureId => $valueIds) {
            $options['selected'][$featureId] = array_values(array_unique([
                ...(array) ($options['selected'][$featureId] ?? []),
                ...array_map('strval', $valueIds),
            ]));
        }

        // ...and its yes/no filters, ticked for the same reason. A flag the
        // shop no longer offers in the panel is still applied by the address —
        // it simply has no box, exactly as a value of a characteristic the
        // panel has stopped drawing has no box.
        $options['flags_on'] = array_values(array_unique([
            ...$options['flags_on'],
            ...$page->flagSet(),
        ]));

        // ...and its price range, shown in the boxes for the same reason: a
        // visitor typing over it and submitting (which posts to the plain
        // category, never back here) is the way off a price-bracket page,
        // exactly as unticking a value or a flag already is. Only when the
        // request itself said nothing — a visitor who HAS typed a price is
        // narrowing further, and that must not be silently overwritten.
        if ($page->price_min !== null && ! $request->filled('price_min')) {
            $options['page_price_min'] = FilterPage::bareAmount($page->price_min);
        }
        if ($page->price_max !== null && ! $request->filled('price_max')) {
            $options['page_price_max'] = FilterPage::bareAmount($page->price_max);
        }

        return view('torgash.catalog::index', [
            'category' => $found,
            'categories' => $this->categoryRows($found),
            // Not on a filter page: the visitor is narrowing, not drilling
            // down, and a row of subcategories there leads out of the set the
            // page is about.
            'subcategories' => collect(),
            'products' => $products,
            'filters' => $options,
            'activeFilters' => $this->listing->activeFilters($request),
            'filterPages' => $filterPages->linksFor($found, $page),
            'filterPage' => $page,
            'sort' => $this->listing->sortOptions($request),
            'listing' => $this->listing->listingOptions($request),
        ]);
    }

    /**
     * The product behind a slug, loaded the way both renditions of the page
     * need it. reachable(), not visible(): a "hidden" product is deliberately
     * absent from every listing but still opens on its own URL.
     */
    protected function findProduct(string $slug): ?Product
    {
        return Product::where('slug', $slug)->reachable()
            ->with('featureAssignments.feature.group', 'featureAssignments.featureValue', 'priceTiers.customerGroup', 'priceTypeOverrides')
            ->first();
    }

    /**
     * The same page as text, for a reader that is a program — see
     * Torgash\Catalog\Seo\ProductText.
     *
     * It does NOT count a view. The counter measures interest, and a crawler
     * walking the catalogue once a night is not interest; the HTML page's own
     * per-session guard exists for exactly the same reason.
     */
    public function productText(string $slug)
    {
        $product = $this->findProduct($slug);

        // A closed product has no text rendition for an outsider at all: the
        // text is its price and its facts.
        if ($product && ! app(\Torgash\Catalog\Support\Assortment::class)->productOpen($product)) {
            abort(404);
        }

        if (! $product) {
            // The redirect table is keyed by the page's real address, so a
            // renamed product answers about the HTML one and the `.md` is put
            // back on afterwards — one table of renames, not two.
            if ($target = $this->redirects->resolve("/product/{$slug}")) {
                return redirect($target.'.md', 301);
            }

            abort(404);
        }

        return response(ProductText::render(hook_filter('catalog.product.view', $product)), 200)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public function product(string $slug, Request $request)
    {
        $product = $this->findProduct($slug);

        if (! $product) {
            return $this->redirectOr404("/product/{$slug}");
        }

        $assortment = app(\Torgash\Catalog\Support\Assortment::class);
        if (! $assortment->productOpen($product)) {
            return $this->closed($product->t('name'), (array) $assortment->closingGroups($product), $product);
        }

        // Other modules can decorate the product before rendering.
        $product = hook_filter('catalog.product.view', $product);

        // The view counted and "viewed recently" updated — for every visit,
        // the ones served from a kept copy of this page too (PLAN §61; the
        // action is CatalogServiceProvider::registerPageCache()'s).
        visit_action('catalog.product_viewed', ['product' => (int) $product->id]);
        page_cache()->tag('product:'.$product->id);

        // The pickers: global characteristics marked as variation axes, never
        // free-form attributes — see Support\Variations.
        $variations = app(\Torgash\Catalog\Support\Variations::class)->for($product)['axes'];

        $clubPriceTypes = PriceType::where('show_on_storefront', true)->where('is_base', false)
            ->where('enabled', true)->with('customerGroups')->orderBy('sort')->get();

        $this->describeProduct($product);

        return view('torgash.catalog::product', [
            'product' => $product,
            'variations' => $variations,
            'clubPriceTypes' => $clubPriceTypes,
        ]);
    }

    /**
     * What the category page says about itself.
     *
     * Its own text, read back as prose, is the meta description until the
     * category has a field of its own (PLAN §30.5) — a shop that wrote a
     * paragraph about "Laptops" has already written the sentence, and leaving
     * it empty until a later release means a release of empty descriptions.
     */
    protected function describeCategory(Category $category, ?string $firstPhoto = null): void
    {
        // A category with no text of its own still says what the page is —
        // most shops never write one, and an empty description hands the
        // snippet to whatever the crawler picks off the page (audit
        // 2026-10-15). Its own field or a `torgash.seo` template still wins.
        $text = Str::limit(Markdown::fromHtml((string) $category->t('description')), 160);

        page_meta()
            ->set([
                'title' => $category->t('name'),
                'description' => $text !== '' ? $text : __(':category in :shop: prices, specifications and availability.', [
                    'category' => $category->t('name'), 'shop' => current_storefront()?->name,
                ]),
                // The picture a shared link shows: the category's own tile,
                // else the first product on the page (audit: none at all).
                'image' => $category->tileImageUrl() ?? $firstPhoto,
            ])
            // ...and then whatever the category itself says, which wins over
            // both — see App\Core\Seo\PageMeta::describe().
            ->describe($category);

        breadcrumbs()->add(__('Catalog'), route('catalog.index'));

        foreach ($category->ancestors() as $ancestor) {
            // A disabled ancestor is still part of where you are, but it has no
            // page — so it is a word in the trail rather than a dead link. A
            // hidden one has a page, and you are in it.
            breadcrumbs()->add(
                (string) $ancestor->t('name'),
                $ancestor->isReachable() ? route('catalog.category', $ancestor->slug) : null,
            );
        }

        breadcrumbs()->add((string) $category->t('name'));
    }

    /**
     * The head and the trail of a filter page.
     *
     * The order is the chain PageMeta documents: what the controller can work
     * out goes in first (the page's own name, and a sentence about the set),
     * then the row's own columns through describe(), and a `torgash.seo`
     * template gets the last word after that — which is the whole reason this
     * kind of page is registered as an SEO subject rather than carrying a
     * second template engine of its own.
     *
     * The category is a LINK in the trail and the page is the last crumb, so
     * "Laptops" stays one click away. The page's own H1 defaults to its name
     * rather than to the category's, which is the difference between a page
     * worth publishing and a duplicate of the one above it.
     */
    protected function describeFilterPage(FilterPage $page, int $total): void
    {
        $category = $page->category;
        $title = $page->title();

        page_meta()
            ->set([
                'title' => $title,
                'h1' => $title,
                'description' => Str::limit(Markdown::fromHtml((string) $page->t('description')), 160),
            ])
            ->describe($page);

        // Nothing to show is nothing to index. Live, not last night's count —
        // see filterPage(). The page still answers, and still says what it is.
        if ($total === 0) {
            page_meta()->noindex(follow: true);
        }

        breadcrumbs()->add(__('Catalog'), route('catalog.index'));

        foreach ($category->ancestors() as $ancestor) {
            breadcrumbs()->add(
                (string) $ancestor->t('name'),
                $ancestor->isReachable() ? route('catalog.category', $ancestor->slug) : null,
            );
        }

        breadcrumbs()->add((string) $category->t('name'), route('catalog.category', $category->slug));
        breadcrumbs()->add($title);
    }

    /**
     * ...and the product page, out of ProductFacts — the same answers the
     * `.md` rendition and the JSON-LD are built from, so the three cannot
     * disagree about the price or the picture.
     *
     * Only the categories a visitor can actually go to become crumbs: a
     * disabled one has no page (the controller 404s on it), and a technical
     * category is one the customer is not supposed to meet. A hidden one has a
     * page but is not part of the navigation, and a trail IS navigation.
     */
    protected function describeProduct(Product $product): void
    {
        $facts = new ProductFacts($product);

        page_meta()
            ->set([
                'title' => $facts->name(),
                'description' => $facts->summary(),
                'type' => 'product',
                'image' => $facts->images(1200)[0]['url'] ?? '',
            ])
            ->describe($product)
            // How a machine finds the text version without being told: the same
            // page at the same address plus .md.
            ->link('alternate', route('catalog.product.text', $product->slug), ['type' => 'text/markdown']);

        breadcrumbs()->add(__('Catalog'), route('catalog.index'));

        // ONE path, not every category the product is filed under: a trail
        // lists where it stands, so the deepest listed category leads (a
        // gateway is "Networking › Gateways", not "Gateways" alone) with its
        // reachable ancestors before it; the first one wins a tie.
        $trail = $product->categories->filter(fn (Category $c) => $c->isListed())
            ->sortByDesc(fn (Category $c) => count($c->ancestors()))
            ->first();

        if ($trail) {
            foreach ($trail->ancestors() as $ancestor) {
                if ($ancestor->isListed()) {
                    breadcrumbs()->add((string) $ancestor->t('name'), route('catalog.category', $ancestor->slug));
                }
            }

            breadcrumbs()->add((string) $trail->t('name'), route('catalog.category', $trail->slug));
        }

        breadcrumbs()->add($facts->name());

        structured_data()->add('product', ProductSchema::for($product));
    }
}
