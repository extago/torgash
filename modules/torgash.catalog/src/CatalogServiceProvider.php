<?php

namespace Torgash\Catalog;

use App\Core\Content\Placeholders;
use App\Core\Display\RuleKinds;
use App\Core\Storefronts\StorefrontRoutes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Content\ProductPlaceholders;
use Torgash\Catalog\Filters\FilterPages;
use Torgash\Catalog\Http\SitemapController;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\FilterRule;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\PriceType;
use Torgash\Catalog\Models\ProductPriceTypeOverride;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Pricing\Prices;
use Torgash\Catalog\Pricing\PriceHistory;
use Torgash\Catalog\Sorting\Sorts;
use Torgash\Catalog\Stock\AvailabilityStatuses;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Catalog\Support\Labels;
use Torgash\Catalog\Support\Measurements;
use Torgash\Catalog\Support\PriceTypes;
use Torgash\Catalog\Support\ShortDescription;
use Torgash\Media\MediaOwners;

class CatalogServiceProvider extends ServiceProvider
{
    /** Per-request memos for the small, hot lookup tables — see each class's docblock. */
    public function register(): void
    {
        $this->app->singleton(Labels::class);
        $this->app->singleton(CategoryTree::class);
        $this->app->singleton(PriceTypes::class);
        $this->app->singleton(Measurements::class);
        $this->app->singleton(ShortDescription::class);
        $this->app->singleton(FilterPages::class);
        $this->app->singleton(Filters\FilterQuery::class);
        $this->app->singleton(Filters\Flags::class);
        $this->app->singleton(Sorts::class);
        $this->app->singleton(Selections\Sources::class);
        $this->app->singleton(Selections\Selection::class);
        $this->app->singleton(AvailabilityStatuses::class);
        // Stateless on purpose (it asks two filters and formats the answer),
        // so it needs no PerRequest reset: what memoises the expensive half is
        // whoever answers `catalog.price.from`.
        $this->app->singleton(Pricing\ShownPrice::class);
        // What stock moved and why (PLAN §42) — holds the cause in force, so
        // it is forgotten with the request.
        $this->app->singleton(Stock\StockMoves::class);
        // Who the visitor is and what is closed to them (PLAN §46).
        $this->app->singleton(Support\Assortment::class);
        \App\Core\Support\PerRequest::forget($this->app, Support\Assortment::class);
        // The active labels and which products their rules matched: one
        // request's answer — a stock that moved changes the next page's.
        \App\Core\Support\PerRequest::forget($this->app, Support\Labels::class);
        \App\Core\Support\PerRequest::forget($this->app, Stock\StockMoves::class);
        // Rows a queue job reads too (an export, a feed, a letter): emptied
        // before each job, or a long-running worker serves what it booted with.
        \App\Core\Support\PerRequest::flushPerJob($this->app, PriceTypes::class, CategoryTree::class, AvailabilityStatuses::class, Sorts::class, Filters\Flags::class);
    }

    public function boot(MediaOwners $mediaOwners): void
    {
        // Its card on core's admin dashboard.
        app(Admin\DashboardWidgets::class)->register();

        // Its part of the demo shop (PLAN §55): categories, characteristics,
        // products, labels — the first part, the others hang off it.
        hook_listen('core.demo.parts', fn (array $parts) => $parts + [
            'catalog' => ['label' => 'Catalogue', 'install' => fn (\App\Core\Demo\DemoPack $pack) => app(Demo\CatalogDemo::class)->install($pack)],
        ]);

        // A category's own picture lives in a folder of its own, not a media row.
        hook_listen('core.files.directories', fn (array $directories) => [...$directories, [
            'disk' => 'public',
            'directory' => Models\Category::ICON_DIR,
            'label' => 'Category pictures',
        ]]);

        // The Media module owns the gallery editor and its endpoints; the
        // catalog only says which of its models may be edited through them.
        //
        // `content: true` adds the second, quieter collection: images that
        // live INSIDE the description rather than beside it as photographs of
        // the product. Same record, same ability, no thumbnails, and they die
        // with the product exactly as its gallery does.
        $mediaOwners->register('product', Product::class, 'catalog.manage', content: true);

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        // Written by the listing's own script, in plain text — see
        // Support\ListingView for why the server never sets them.
        \Illuminate\Cookie\Middleware\EncryptCookies::except([
            Support\ListingView::PER_PAGE_COOKIE,
            Support\ListingView::LAYOUT_COOKIE,
        ]);

        // Not locale-prefixed: a sitemap lives at one well-known URL, not per-locale.
        Route::middleware('web')->get('/sitemap.xml', [SitemapController::class, 'index'])->name('catalog.sitemap');
        // One page of it, for a catalogue past 50 000 addresses — see the
        // controller. Registered always: the index names these addresses and a
        // crawler that has cached one must not meet a 404 the day the shop
        // shrinks back under the limit.
        Route::middleware('web')->get('/sitemap-{page}.xml', [SitemapController::class, 'index'])
            ->where('page', '[0-9]+')->name('catalog.sitemap.page');

        // The reader's routes first: `products/trash` and the rest of the
        // editing routes stand under catalog.manage, which includes
        // catalog.view (Gate::before in core).
        Route::middleware(['web', 'auth', 'can:catalog.view'])
            ->prefix('admin/catalog')
            ->name('admin.catalog.')
            ->group(dirname(__DIR__).'/routes-admin-view.php');

        Route::middleware(['web', 'auth', 'can:catalog.manage'])
            ->prefix('admin/catalog')
            ->name('admin.catalog.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        // A product's categories are read per CARD by three different askers —
        // the `product_category` display rule, a price type's markup rule and
        // a motivation card — each through `$product->categories`, which
        // lazy-loads one query per product. Measured: 18 queries on the home
        // page, 12 on the catalogue. So when a page announces its set, the
        // relation is loaded for all of it in one query, early (priority 1)
        // so it is there before any other listener asks. Loaded, not
        // re-loaded: a set that already carries it pays nothing.
        // A card's photo following the pointer (card_photos_max): one small
        // delegated script on every storefront page rather than per card, since
        // cards also arrive over HTMX into pages that drew none at first.
        hook_listen('core.footer', function (string $html) {
            if ((int) setting('torgash.catalog', 'card_photos_max') < 2) {
                return $html;
            }

            return $html.'<script nonce="'.csp_nonce().'" src="'.e(module_asset('torgash.catalog', 'card-photos.js')).'" defer></script>';
        });

        // What the theme configurator offers to look at: the catalogue, one
        // category and one product, the newest the shop shows. Paths, not
        // addresses — core puts them under the storefront's own domain.
        hook_listen('core.theme.preview_pages', function (array $pages) {
            $pages[] = ['label' => 'Catalogue', 'icon' => 'layout-grid', 'path' => route('catalog.index', [], false), 'position' => 10];

            // One with subcategories when there is one: the theme's
            // "Category page" settings are about those.
            $listed = app(\Torgash\Catalog\Support\CategoryTree::class)->ordered(listedOnly: true);
            $parents = $listed->pluck('parent_id')->filter()->map(fn ($id) => (int) $id)->flip();
            $category = $listed->first(fn (Category $c) => $parents->has((int) $c->id))
                ?? Category::query()->listed()->orderBy('id')->first();

            if ($category) {
                $pages[] = ['label' => 'Category page', 'icon' => 'folder', 'path' => route('catalog.category', $category->slug, false), 'position' => 20];
            }

            if ($product = Product::query()->visible()->orderByDesc('id')->first()) {
                $pages[] = ['label' => 'Product page', 'icon' => 'package', 'path' => route('catalog.product', $product->slug, false), 'position' => 30];
            }

            return $pages;
        });

        hook_listen('catalog.products.listed', function (array $context) {
            $products = collect($context['products'] ?? [])->filter(fn ($p) => $p instanceof Product)->values();

            if ($products->isNotEmpty()) {
                (new \Illuminate\Database\Eloquent\Collection($products->all()))->loadMissing('categories');
            }
        }, 1);

        // A page of the API is a page of products: announced the way the
        // storefront announces a grid, so every module that already answers a
        // set in one query (stock, taxes, this module's own short
        // descriptions) does it for a JSON client too, instead of paying a
        // query per row.
        hook_listen('api.resource.listed', function (array $context) {
            $items = collect($context['items'] ?? [])->filter(fn ($item) => $item instanceof Product);

            if ($items->isEmpty()) {
                return;
            }

            app(ShortDescription::class)->prime($items);
            hook_action('catalog.products.listed', ['products' => $items]);
        });

        $this->registerPageCache();
        $this->registerMachineReaders();
        $this->registerFeaturePlaces();
        $this->registerFilterFlags();
        $this->registerSorts();
        $this->registerProductBlock();
        $this->registerVariationsList();
        $this->registerPopularity();
        $this->announceRestocks();
        $this->announceStockMoves();
        $this->recordPriceHistory();
        $this->registerAvailabilityStatuses();
        $this->registerEximHooks();
        $this->registerShippingHooks();
        $this->registerCurrencyHooks();
        $this->registerDisplayRules();
        $this->seoSubjects();

        // Rules make pages, and the catalogue they are about moves on its own —
        // a supplier's delivery is what turns a thin combination into a page
        // worth publishing. Nothing fires until a cron runs schedule:run.
        hook_listen('core.schedule', function (array $context) {
            $context['schedule']->call(function () {
                // Asked when the job would run, not when the provider boots:
                // a shop with no rules must not get a background task a night.
                if (FilterRule::where('enabled', true)->exists()) {
                    Jobs\GenerateFilterPages::startIfIdle();
                }
            })->dailyAt('03:20')->name('catalog:filter-pages');
        });

        // What an admin-written block may say about the product beside it —
        // the price, the club price, its name. Registered here for the same
        // reason the contact tokens are registered in core: the tokens belong
        // to whoever owns the data, and the HTML block owns none of it.
        app(ProductPlaceholders::class)->register(app(Placeholders::class));

        // Answered only if the API module is installed and asks — the class
        // referenced inside lives there, so the listener body must never run
        // without it. Same soft-dependency shape as the exim hooks.
        hook_listen('api.resources', fn () => (new Api\CatalogResources)->register());

        // What must be true about the catalogue, for whoever is watching. A filter
        // nobody fires when `torgash.guard` is not installed, so this is a
        // statement about our own data rather than a dependency on theirs.
        hook_listen('guard.invariants', fn (array $all) => $all
            + (new Invariants\CatalogInvariants)->register());
    }

    /**
     * Pages kept ready for guests (PLAN §61): the catalogue's kinds of page,
     * what it keeps about a visitor, what its pages do for every visit — and
     * which of its writes no guest page can see.
     */
    protected function registerPageCache(): void
    {
        page_cache()
            ->kind('home', 'Home page')
            ->kind('category', 'Categories and the catalogue')
            ->kind('filter_page', 'Filter pages')
            ->kind('product', 'Product pages')
            ->kind('brand', 'Brands')
            // "Counted already" — read only by the visit action below.
            ->harmless(session: ['viewed_products'])
            // Drawn by the "Viewed recently" block, which is personal by it;
            // nothing else reads it, so its holders still give copies.
            ->personal(session: [Support\RecentlyViewed::SESSION_KEY], copies: true)
            // How the visitor has a listing laid out: what the page WILL use
            // (the address first, then the cookie), so a cookie saying the
            // default is the default's copy.
            ->vary(
                'listing',
                fn (\Illuminate\Http\Request $request) => app(Support\ListingView::class)->perPage($request).'/'.app(Support\ListingView::class)->layout($request),
                cookies: [Support\ListingView::PER_PAGE_COOKIE, Support\ListingView::LAYOUT_COOKIE],
            )
            // Counted in the product's own row on every view and sale; a label
            // reading them is right again within the copy's hour. Stock is
            // announced on its own (below), by product.
            ->unseen('m_catalog_products', ['views_count', 'sales_count', 'popularity', 'stock', 'updated_at'])
            // A label's dates, and "new for N days", put badges on and take
            // them off with nothing written.
            ->changes(function () {
                $next = page_cache()->nextDateIn('m_catalog_labels');

                foreach (Label::query()->where('enabled', true)->get() as $label) {
                    foreach ((array) ($label->conditions['rules'] ?? []) as $rule) {
                        if (($rule['field'] ?? null) !== 'age_days') {
                            continue;
                        }

                        $days = (int) ($rule['value'] ?? 0);
                        $youngest = DB::table('m_catalog_products')->where('created_at', '>', now()->subDays($days))->min('created_at');
                        $at = $youngest ? Carbon::parse($youngest)->addDays($days)->addSecond() : null;

                        if ($at !== null && ($next === null || $at->lt($next))) {
                            $next = $at;
                        }
                    }
                }

                return $next;
            })
            // One view per product per session, never a crawler's points —
            // for the visits served from a copy as for the drawn ones.
            ->onVisit('catalog.product_viewed', function (array $data, \Illuminate\Http\Request $request) {
                $id = (int) ($data['product'] ?? 0);
                $seen = (array) session('viewed_products', []);

                if (! in_array($id, $seen, true)) {
                    // A plain query: a view is not an edit of the product, and
                    // must not move updated_at or wake the model's listeners.
                    DB::table('m_catalog_products')->where('id', $id)->increment('views_count');
                    app(Support\Popularity::class)->viewed($id, $request);
                    session(['viewed_products' => array_slice([...$seen, $id], -200)]);
                }

                app(Support\RecentlyViewed::class)->remember($id);
            })
            // A city page is a visitor saying where they are (filterPage()).
            ->onVisit('catalog.place_chosen', fn (array $data) => hook_action('storefront.place_chosen', [
                'place_id' => (int) ($data['place_id'] ?? 0),
                'source' => 'geo_page',
            ]));

        // What a kept page shows, by the products it announces: a sale moves
        // one product's stock, and only the pages that show it are drawn again.
        hook_listen('catalog.products.listed', function (array $context) {
            if (page_cache()->current() === null) {
                return;
            }

            foreach ($context['products'] ?? [] as $product) {
                if ($product instanceof Product) {
                    page_cache()->tag('product:'.$product->id);
                }
            }
        });

        hook_listen('catalog.stock.moved', function (array $move) {
            $after = $move['after'] ?? null;

            // Sold out, or back: a listing that hides what is out of stock,
            // the filter counts and the "in stock" flag all move — every page.
            if ($after !== null && ((int) $after > 0) !== ((int) $after - (int) $move['delta'] > 0)) {
                page_cache()->discardAll('stock of product '.$move['product_id'].' crossed zero');

                return;
            }

            page_cache()->discard('product:'.$move['product_id']);
        });
    }

    /**
     * What core's two machine-facing files say about the catalogue.
     *
     * Core owns robots.txt and llms.txt and knows the name of no module, so the
     * catalogue answers for its own half: the sitemap it publishes, the search
     * page nobody should index (a results page per query string is the classic
     * way a shop fills an index with itself), and the entry points an agent
     * needs before it can walk anything.
     *
     * The `.md` convention is stated in words there rather than enumerated:
     * one line teaches every product address at once, and a list of them is
     * what the sitemap already is.
     */
    protected function registerMachineReaders(): void
    {
        hook_listen('core.robots.sitemaps', fn (array $sitemaps) => [...$sitemaps, route('catalog.sitemap')]);

        // A result page is the visitor's question, not the shop's content:
        // endless, thin, and duplicated from the pages it lists.
        hook_listen('core.seo.private_routes', fn (array $names) => [...$names, 'catalog.search']);

        hook_listen('core.llms.sections', fn (array $sections) => [...$sections, [
            'title' => __('Catalogue'),
            'position' => 10,
            'text' => __('Any product page is also available as text: add .md to its address, for example /product/example.md. The same page in another language is that address under the language prefix.'),
            'items' => [
                ['label' => __('All products'), 'url' => route('catalog.index'), 'note' => __('categories, filters and the product pages themselves')],
                ['label' => __('Sitemap'), 'url' => route('catalog.sitemap'), 'note' => __('every address the shop publishes')],
            ],
        ]]);
    }

    /**
     * The two yes/no filters the catalogue can answer from its own columns, and
     * the settings field that decides which of them a shop offers.
     *
     * `in_stock` is what the panel already had. `on_sale` is the one the shop
     * asked for, and it is ONE box rather than two on purpose: a visitor
     * ticking "on sale" is asking a single question, and answering it with
     * "cheaper than its RRP" beside "in a promotion" would make the shop's own
     * implementation of a discount into the customer's problem. So the
     * catalogue states the branch it owns — a price below the recommended one,
     * both real columns, so it stays SQL — and every other way a price can be
     * cut adds an OR branch through `catalog.filters.discounted`
     * (`torgash.promotions` answers it; see its provider).
     *
     * A branch is handed the nested query and must narrow it in SQL. It cannot
     * be a PHP predicate: this filter is a WHERE on a paginated grid, and a
     * collection filtered after the fact leaves the pager counting products the
     * page does not contain — the same line Label::matchedIdsFor() draws
     * between its SQL and its PHP modes.
     */
    /**
     * The three places this module itself draws a characteristic in.
     *
     * Registered rather than written into the feature form, for the reason
     * every other list here is: a module that shows characteristics somewhere
     * of its own adds its place and becomes tickable without a migration on
     * this module's table. None of them decides whether the characteristic can
     * be FILTERED by — see Features\Places.
     */
    protected function registerFeaturePlaces(): void
    {
        hook_listen('catalog.feature.places', fn (array $places) => $places + [
            'filters' => [
                'label' => 'Filter panel',
                'help' => 'Offered as a control in the sidebar. Filtering by it in an address keeps working either way.',
            ],
            'specs' => [
                'label' => 'Specifications',
                'help' => 'The characteristics section of the product page — and every machine-readable rendition of it.',
            ],
            'buy_box' => [
                'label' => 'Under the buy button',
                'help' => 'The short list in the buy panel. How many of them fit there is a setting.',
            ],
        ]);
    }

    protected function registerFilterFlags(): void
    {
        hook_listen('catalog.filters.flags', fn (array $flags) => $flags + [
            'in_stock' => [
                'label' => 'In stock only',
                // "Orderable right now" across every registered availability
                // status, not a bare stock>0 — a preorder is in stock for this
                // box's purposes, a discontinued product with 50 left is not.
                'apply' => fn (Builder $query) => app(AvailabilityStatuses::class)->applyOrderable($query),
            ],
            'on_sale' => [
                'label' => 'On sale',
                'apply' => fn (Builder $query) => $query->where(function (Builder $q) {
                    // The catalogue's own branch asks the same number the
                    // struck-through price is (Product::oldPrice()), or the box
                    // and the card would disagree about what "on sale" means.
                    $reference = setting('torgash.catalog', 'old_price_source') === 'lowest' ? 'prior_lowest_price' : 'list_price';
                    $q->where(fn (Builder $rrp) => $rrp->whereNotNull($reference)
                        ->whereColumn($reference, '>', 'price'));

                    foreach (hook_filter('catalog.filters.discounted', [], []) as $branch) {
                        if (is_callable($branch)) {
                            $q->orWhere(fn (Builder $sub) => $branch($sub));
                        }
                    }
                }),
            ],
        ]);

        // Which flags exist is not something manifest.json can know — a module
        // registers one — so the tick list is filled in on the way to the
        // screen and on the way back. See SettingsController::definitions().
        hook_listen('core.settings.fields', function (array $fields, array $context) {
            if (($context['module'] ?? null) !== 'torgash.catalog') {
                return $fields;
            }

            return array_map(function (array $field) {
                if (($field['key'] ?? null) === 'filter_flags') {
                    $field['options'] = app(Filters\Flags::class)->options();
                }

                if (in_array($field['key'] ?? null, ['catalog_sorts', 'default_sort'], true)) {
                    $field['options'] = app(Sorts::class)->options();
                }

                if (($field['key'] ?? null) === 'availability_on_zero_status') {
                    $field['options'] = app(AvailabilityStatuses::class)->options();
                }

                return $field;
            }, $fields);
        });
    }

    /**
     * The orders a listing can answer in — the same shape as the filter flags
     * above, and open to any module through `catalog.sorts` (PLAN §31.1).
     *
     * Four, which is what the storefront can state from its own columns. Each
     * `apply` ORDERS a query and narrows nothing, and none of them closes the
     * ordering with `id` — {@see Sorting\Sorts::apply()} does that once, for
     * every sort in the registry including a module's own, because a page
     * boundary falling inside a tie is a product shown twice or never.
     *
     * Cheapest/dearest sorts by the stored `price`, which is the shop's own
     * books, and NOT by `effectivePrice()` — that is this visitor's price, and
     * working it out per row cannot be indexed at the size a real catalogue
     * reaches. A deliberate simplification, agreed with the owner (PLAN §31.1):
     * a customer group with its own price list sees the shop's order, at their
     * own prices.
     *
     * "By name" reads the CURRENT language with the base one behind it, which
     * is `t()`'s own rule expressed in SQL — sorting Russian names by their
     * English spelling would look like no sort at all to the reader. The
     * database is created with the ICU root locale precisely so that this
     * comparison folds case for every alphabet (see CLAUDE.md).
     */
    protected function registerSorts(): void
    {
        hook_listen('catalog.sorts', fn (array $sorts) => $sorts + [
            // The points column, never `sales_count` — that one counts UNITS,
            // so fifty screws on one order outrank a laptop three people
            // chose. See Popularity for what moves it.
            'popular' => [
                'label' => 'Most popular',
                'icon' => 'flame',
                'apply' => fn (Builder $query) => $query->orderBy('popularity', 'desc'),
            ],
            'newest' => [
                'label' => 'Newest first',
                'icon' => 'sparkles',
                'apply' => fn (Builder $query) => $query->orderBy('created_at', 'desc'),
            ],
            'price_asc' => [
                'label' => 'Cheapest first',
                'icon' => 'arrow-down-narrow-wide',
                'apply' => fn (Builder $query) => $query->orderBy('price'),
            ],
            'price_desc' => [
                'label' => 'Dearest first',
                'icon' => 'arrow-down-wide-narrow',
                'apply' => fn (Builder $query) => $query->orderBy('price', 'desc'),
            ],
            'name' => [
                'label' => 'By name',
                'icon' => 'arrow-down-a-z',
                'apply' => fn (Builder $query) => $query->orderByRaw(
                    'coalesce(name->>?, name->>?)',
                    [app()->getLocale(), content_base_locale()],
                ),
            ],
        ]);
    }

    /**
     * "Some products, here" as a content block, and the registry of where those
     * products come from (PLAN §31.4). The block type is registered only when
     * the blocks module is there to ask for it; the sources are answered lazily
     * whoever asks.
     */
    /**
     * "All variants of this product" under the buy box (cart's
     * `cart.buy_box.bottom`; cart draws the position and knows nothing of
     * variations): a whole-width quiet button and a window with the group as
     * a table, every row bought as itself. Other engines put
     * the same list in a product tab; here it is secondary, so it stays a
     * button. Setting `variations_list`.
     */
    protected function registerVariationsList(): void
    {
        hook_listen('cart.buy_box.bottom', function (string $html, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product || ! $product->variation_group || ! setting('torgash.catalog', 'variations_list', true)) {
                return $html;
            }

            $variations = app(\Torgash\Catalog\Support\Variations::class)->for($product);
            $ids = $variations['offered']->pluck('id')->all();

            if (count($ids) < 2) {
                return $html;
            }

            // The rows as a grid would have them: pictures and prices in one
            // go, and the page's set announced so stock, the basket's buttons
            // and anything else per product answer in one query each.
            $members = Product::query()->whereIn('id', $ids)->withCardData()->get()
                ->sortBy(fn (Product $member) => array_search($member->id, $ids, true))->values();
            hook_action('catalog.products.listed', ['products' => $members]);

            return $html.view('torgash.catalog::partials.variations-list', [
                'variantsOf' => $product,
                'variantsMembers' => $members,
                'variantsAxes' => $variations['axes'],
                'variantsValues' => $variations['values'],
            ])->render();
        });
    }

    protected function registerProductBlock(): void
    {
        hook_listen('catalog.product_sources', fn (array $sources) => Selections\ProductBlock::sources($sources));

        Selections\ProductBlock::register();
    }

    /**
     * Points for an order, awarded by the CATALOGUE — the column is its own,
     * and checkout has no business knowing what a product is sorted by.
     *
     * A hook name is the whole dependency: with checkout uninstalled this
     * listener is never called and costs nothing, which is the same seam
     * `catalog.stock.warehouses` and friends are built on. `sales_count` is
     * still incremented by checkout beside this, counting units for the
     * labels — see Support\Popularity for why the two are different questions.
     */
    /**
     * "This product can be bought again", announced for every path that writes
     * the column through the MODEL — the admin form, an exim import, 1C, an
     * order being cancelled back onto the shelf.
     *
     * The warehouses module writes the aggregate with a raw query and
     * announces through `Product::syncStockTo()` instead; between the two,
     * every way stock moves is covered, which is what a promise made on the
     * product page ("tell me when it is back") needs in order to be keepable.
     *
     * A CROSSING, not a state: only a product that could not be bought a
     * moment ago and can be now. Both halves are asked of the same status,
     * because a status edit is the other way this changes — "discontinued"
     * back to "in stock" is a product returning just as much as a delivery is.
     */
    /**
     * A product's own stock column moved (PLAN §42): the form, an import, 1C,
     * the API — anything that saves the product. `Product::adjustStock()`
     * announces its own with more to say and saves quietly; a product kept per
     * warehouse has its column written by `syncStockTo()`, which saves nothing
     * through the model, and its rows announce themselves. A new product with
     * stock is a movement from nothing.
     */
    protected function announceStockMoves(): void
    {
        Product::created(function (Product $product) {
            if ((int) $product->stock !== 0) {
                app(Stock\StockMoves::class)->record($product->id, null, (int) $product->stock, (int) $product->stock);
            }
        });

        Product::updated(function (Product $product) {
            $moves = app(Stock\StockMoves::class);

            if ($moves->isQuiet() || ! $product->wasChanged('stock')) {
                return;
            }

            $moves->record($product->id, null, (int) $product->stock - (int) $product->getOriginal('stock'), (int) $product->stock);
        });
    }

    protected function announceRestocks(): void
    {
        Product::updated(function (Product $product) {
            if (! $product->wasChanged(['stock', 'availability_status'])) {
                return;
            }

            $statuses = app(AvailabilityStatuses::class);

            $was = $statuses->isOrderableFor(
                (string) ($product->getOriginal('availability_status') ?? ''),
                (int) $product->getOriginal('stock'),
            );

            if (! $was && $product->isOrderable()) {
                Product::announceRestock($product);
            }
        });
    }

    /**
     * What a product has cost (PLAN §36, Pricing\PriceHistory). Three ways in,
     * each for what the others cannot see:
     *
     * - any save that moved a column the price is made of — imports, 1C and
     *   the API save through the model;
     * - the product form's own funnel, LATE, because tiers and price-type
     *   overrides are written after the product row and are part of the
     *   price too (read fresh: the form's instance holds the old relations);
     * - an hourly sweep, because a promotion that starts, ends or opens its
     *   weekly window changes the price with nothing written at all.
     *
     * Recording the same price twice writes nothing, so the three overlapping
     * is harmless; a nightly prune drops what no window can reach.
     */
    protected function recordPriceHistory(): void
    {
        Product::saved(function (Product $product) {
            if ($product->wasRecentlyCreated || $product->wasChanged(['price', 'list_price', 'status'])) {
                app(PriceHistory::class)->record($product);
            }
        });

        hook_listen('catalog.product.saved', function (array $context) {
            if ($product = Product::withCardData()->find($context['product']->id ?? 0)) {
                app(PriceHistory::class)->record($product);
            }
        }, 90);

        hook_listen('core.schedule', function (array $context) {
            $context['schedule']->call(fn () => app(PriceHistory::class)->sweep())
                ->hourly()->name('catalog:price-history')->withoutOverlapping();
            $context['schedule']->call(fn () => app(PriceHistory::class)->prune())
                ->dailyAt('04:10')->name('catalog:price-history-prune');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([Console\PriceHistoryCommand::class]);
        }
    }

    protected function registerPopularity(): void
    {
        hook_listen('checkout.order_placed', function (array $context) {
            // Resolved inside the listener, never captured at boot: a listener
            // outlives the request, and a captured singleton is a static
            // wearing a different hat (CLAUDE.md's rule for per-request memos).
            app(Support\Popularity::class)->ordered($context['order']->items ?? []);
        });
    }

    /**
     * The base availability statuses — a second axis from `status`, open to
     * any module through this same hook (PLAN §31.3). Three cover what the
     * catalogue already needs to say without inventing anything a shop has
     * not asked for; "in transit", a warranty hold or anything else a real
     * shop wants is one more `+ [...]` entry away, in a module of its own.
     *
     * Named `preorder`, never `backorder`: an ORDER already has a status by
     * that name (`Torgash\Checkout\Orders\PaymentState`'s sibling concept,
     * `OrderStock::canCover()`) meaning "this paid order outran the shelf" —
     * a different question from "this product is sold ahead of stock on
     * purpose". Two "backorders" meaning two different things would confuse
     * support and the logs alike.
     */
    protected function registerAvailabilityStatuses(): void
    {
        hook_listen('catalog.availability_statuses', fn (array $statuses) => $statuses + [
            'in_stock' => [
                'label' => 'In stock',
                'orderable' => true,
                'tracks_stock' => true,
                'allow_negative' => false,
                'schema' => 'https://schema.org/InStock',
            ],
            'out_of_stock' => [
                'label' => 'Out of stock',
                'orderable' => false,
                'tracks_stock' => true,
                'allow_negative' => false,
                'schema' => 'https://schema.org/OutOfStock',
            ],
            // Orderable however negative the number goes: the whole point is
            // selling ahead of the shelf. tracks_stock stays true so the count
            // keeps meaning something for reporting and for 1C, it just never
            // blocks the button — see AvailabilityStatuses::isOrderable().
            'preorder' => [
                'label' => 'Preorder',
                'orderable' => true,
                'tracks_stock' => true,
                'allow_negative' => true,
                'schema' => 'https://schema.org/PreOrder',
            ],
            // Discontinued for good: not orderable, and the number is nobody's
            // business any more. Visibility (still on the site, or hidden) is
            // the existing `status`/`noindex` pair's job, not this one's.
            'discontinued' => [
                'label' => 'Discontinued',
                'orderable' => false,
                'tracks_stock' => false,
                'allow_negative' => false,
                'schema' => 'https://schema.org/Discontinued',
            ],
            // Always buyable, the number never consulted: a digital good, a
            // service, anything whose "stock" is not a physical count.
            'unlimited' => [
                'label' => 'Always in stock',
                'orderable' => true,
                'tracks_stock' => false,
                'allow_negative' => false,
                'schema' => 'https://schema.org/InStock',
            ],
        ]);

        // Each status by its own name in the admin's search box — "Предзаказ"
        // leads to where preorder is configured. The shop's renamed and own
        // statuses are data, so no manifest keyword could say them.
        hook_listen('core.admin.search', function (array $entries) {
            $statuses = app(AvailabilityStatuses::class);

            foreach ($statuses->all() as $key => $def) {
                $entries[] = [
                    'title' => $def['label'],
                    'context' => __('Availability statuses'),
                    'keywords' => [$key],
                    'url' => route('admin.catalog.availability-statuses.edit', $key),
                    'icon' => 'package-check',
                    'kind' => 'setting',
                ];
            }

            return $entries;
        });
    }

    /**
     * "On which products" — the catalogue's half of a display rule.
     *
     * Registered rather than built into the engine, because core must not know
     * what a category is and the blocks module must not know either. The
     * product comes from whatever is being hidden: a block in a product-page
     * zone is handed one in its zone context; the same rule on the home page
     * has none, and every kind here answers No rather than guessing.
     */
    protected function registerDisplayRules(): void
    {
        RuleKinds::register('product_category', [
            'label' => 'Product category',
            'group' => 'what',
            'value' => 'options',
            // In TREE order and indented: a flat list sorted by `sort`
            // interleaves the levels, so "Gaming laptops" sits nowhere near
            // "Laptops" and the shop owner picks the wrong one.
            'options' => fn () => app(CategoryTree::class)->options(),
            // ...and the names alone for a rule's description: "is in
            // iPhone Duo", not "is in — — iPhone Duo".
            'labels' => fn (array $ids) => app(CategoryTree::class)->names($ids),
            'operators' => [
                'in' => 'is in',
                'not_in' => 'is not in',
            ],
            'matches' => function (array $rule, array $context) {
                $product = $context['product'] ?? null;
                $wanted = array_map('intval', (array) ($rule['value'] ?? []));

                if (! $product instanceof Product) {
                    return false;
                }

                // "is in Electronics" is true of a camera: a category is its
                // subcategories (Support\CategoryTree).
                $wanted = app(CategoryTree::class)->descendants($wanted);
                $has = $product->categories->pluck('id')->intersect($wanted)->isNotEmpty();

                return ($rule['operator'] ?? 'in') === 'not_in' ? ! $has : $has;
            },
            // The same question in SQL, subtree and all (DisplayRules::narrow).
            'narrow' => function (Builder $products, array $rule) {
                $wanted = app(CategoryTree::class)->descendants(array_map('intval', (array) ($rule['value'] ?? [])));
                $in = fn (Builder $category) => $category->whereKey($wanted);

                return ($rule['operator'] ?? 'in') === 'not_in'
                    ? $products->whereDoesntHave('categories', $in)
                    : $products->whereHas('categories', $in);
            },
        ]);

        // "Which category is OPEN" — the question a block in a category page's
        // zone asks (catalog.category.top/bottom hand over `category`), as
        // against product_category, which asks about a product. With its
        // subtree, like every rule that names a category. The catalogue's own
        // root (/catalog) has no category, and a rule about one fails closed
        // there — which is what an unanswerable rule does everywhere.
        RuleKinds::register('listing_category', [
            'label' => 'Category being viewed',
            'group' => 'where',
            'value' => 'options',
            'options' => fn () => app(CategoryTree::class)->options(),
            'labels' => fn (array $ids) => app(CategoryTree::class)->names($ids),
            'operators' => [
                'in' => 'is in',
                'not_in' => 'is not in',
            ],
            'matches' => function (array $rule, array $context) {
                $category = $context['category'] ?? null;

                if (! $category instanceof Category) {
                    return false;
                }

                $has = in_array($category->id, app(CategoryTree::class)->descendants(array_map('intval', (array) ($rule['value'] ?? []))), true);

                return ($rule['operator'] ?? 'in') === 'not_in' ? ! $has : $has;
            },
        ]);

        RuleKinds::register('product_label', [
            'label' => 'Product label',
            'group' => 'what',
            'value' => 'options',
            'options' => fn () => Label::orderBy('sort')->get()
                ->mapWithKeys(fn (Label $l) => [$l->id => $l->t('name')])->all(),
            'operators' => [
                'in' => 'carries',
                'not_in' => 'does not carry',
            ],
            'matches' => function (array $rule, array $context) {
                $product = $context['product'] ?? null;
                $wanted = array_map('intval', (array) ($rule['value'] ?? []));

                if (! $product instanceof Product) {
                    return false;
                }

                // Assigned labels only, not the rule-driven ones: a rule about
                // "Hit" would otherwise depend on a sales counter that moves,
                // and a block that appears by itself is a support ticket.
                $has = $product->labels->pluck('id')->intersect($wanted)->isNotEmpty();

                return ($rule['operator'] ?? 'in') === 'not_in' ? ! $has : $has;
            },
            'narrow' => function (Builder $products, array $rule) {
                $in = fn (Builder $label) => $label->whereKey(array_map('intval', (array) ($rule['value'] ?? [])));

                return ($rule['operator'] ?? 'in') === 'not_in'
                    ? $products->whereDoesntHave('labels', $in)
                    : $products->whereHas('labels', $in);
            },
        ]);

        RuleKinds::register('product_stock', [
            'label' => 'Product stock',
            'group' => 'what',
            'value' => 'none',
            'operators' => [
                'in_stock' => 'is in stock',
                'out_of_stock' => 'is out of stock',
            ],
            'matches' => function (array $rule, array $context) {
                $product = $context['product'] ?? null;

                if (! $product instanceof Product) {
                    return false;
                }

                // "In stock" here means orderable right now — a preorder or an
                // untracked product counts, a discontinued one does not, the
                // same answer the storefront's own "in stock only" box gives
                // (PLAN §31.3).
                return ($rule['operator'] ?? '') === 'out_of_stock'
                    ? ! $product->isOrderable()
                    : $product->isOrderable();
            },
        ]);

        // "Show this where the product has nothing of its own to say."
        //
        // The case it was added for is a tab that stands in for a missing
        // description — a catalogue of three thousand rows has a few hundred
        // with no text at all, and a paragraph about the CATEGORY is better
        // there than an empty page. Being a rule kind rather than a switch on
        // the tab, it answers for a block, a motivation card or anything else
        // that asks core's engine, and it is registered HERE because every one
        // of these things is the catalogue's own data.
        //
        // Each part is read as the PAGE would print it, not as the column
        // stands: a short description composed from characteristics is not
        // missing, and a photograph inherited from the legacy `image_url` is a
        // photograph. Several parts chosen at once mean ALL of them — the rule
        // list's own all/any is what "either of these" is written with.
        RuleKinds::register('product_content', [
            'label' => 'What the product has',
            'group' => 'what',
            'value' => 'options',
            'options' => fn () => [
                'description' => __('Description'),
                'short_description' => __('Short description'),
                'photos' => __('Photographs'),
                'characteristics' => __('Characteristics'),
            ],
            'operators' => [
                'empty' => 'is missing',
                'filled' => 'is there',
            ],
            'matches' => function (array $rule, array $context) {
                $product = $context['product'] ?? null;

                if (! $product instanceof Product) {
                    return false;
                }

                $wanted = array_filter((array) ($rule['value'] ?? []));

                if ($wanted === []) {
                    return false;
                }

                $has = fn (string $part) => match ($part) {
                    'description' => $product->hasDescription(),
                    'short_description' => app(ShortDescription::class)->for($product) !== '',
                    'photos' => $product->galleryImages()->isNotEmpty() || $product->externalImageUrls() !== [],
                    'characteristics' => ! empty($product->features)
                        || $product->featureAssignments->contains(fn ($pf) => $pf->display() !== null),
                    default => false,
                };

                $filled = ($rule['operator'] ?? '') === 'filled';

                foreach ($wanted as $part) {
                    if ($has((string) $part) !== $filled) {
                        return false;
                    }
                }

                return true;
            },
        ]);
    }

    /**
     * A product priced in another currency keeps its TYPED number; what moves
     * is the shop's own price for it. So when a rate changes, the books amounts
     * derived from it are recomputed — here, because core announces the change
     * and knows nothing about products.
     *
     * The service is resolved INSIDE the listener, never captured at boot: a
     * listener outlives the request, and a captured object would be a static
     * wearing a different hat.
     */
    protected function registerCurrencyHooks(): void
    {
        hook_listen('core.currency.rate_changed', function (array $context) {
            app(Prices::class)->rebase((string) $context['code']);
        });

        // Derived prices are rounded to the books currency's places.
        hook_listen('core.currency.precision_changed', function () {
            $prices = app(Prices::class);
            foreach (\Torgash\Catalog\Models\Product::query()->whereNotNull('price_currency')->distinct()->pluck('price_currency') as $code) {
                $prices->rebase((string) $code);
            }
        });

        // A currency the shop deleted cannot go on being the currency a product
        // is priced in: the rebase would stop silently and leave a price that
        // looks maintained. The books amount it last had is kept.
        hook_listen('core.currency.deleted', function (array $context) {
            app(Prices::class)->forget((string) $context['code']);
        });
    }

    /**
     * What the delivery module needs to know about a cart to decide whether a
     * method applies to it: which categories its products are in, and which
     * specs they carry.
     *
     * Answered here for the same reason the exim hooks are: the hook belongs to
     * the module asking, the data belongs to this one, and neither appears in
     * the other's manifest. A shop with no delivery module installed simply
     * never fires these.
     */
    protected function registerShippingHooks(): void
    {
        hook_listen('shipping.cart_lines', function (array $lines, array $context) {
            $items = $context['cart']->items();

            if ($items->isEmpty()) {
                return $lines;
            }

            // Two queries for the whole cart, not two per line: a condition is
            // evaluated on every quote, and quotes happen on every keystroke in
            // the town field.
            $products = $items->map(fn ($item) => $item->product);
            $categories = DB::table('m_catalog_category_product')
                ->whereIn('product_id', $products->pluck('id'))
                ->get(['product_id', 'category_id'])
                ->groupBy('product_id')
                ->map(fn ($rows) => $rows->pluck('category_id')->all());
            $assigned = DB::table('m_catalog_product_features as pf')
                ->join('m_catalog_features as f', 'f.id', '=', 'pf.feature_id')
                ->leftJoin('m_catalog_feature_values as fv', 'fv.id', '=', 'pf.feature_value_id')
                ->whereIn('pf.product_id', $products->pluck('id'))
                // In the BASE language: a condition is typed once, in the
                // language every other one falls back to.
                ->selectRaw('pf.product_id, f.name->>? as name, f.code, coalesce(pf.value_text, fv.value->>?) as value', array_fill(0, 2, content_base_locale()))
                ->get()
                ->groupBy('product_id');

            foreach ($products as $product) {
                $features = (array) ($product->features ?? []);

                // The managed catalogue's values are merged in under both the
                // feature's name and its code, so a condition can name either.
                foreach ($assigned[$product->id] ?? [] as $row) {
                    $features[$row->name ?: $row->code] = (string) $row->value;
                    if ($row->code) {
                        $features[$row->code] = (string) $row->value;
                    }
                }

                $lines[] = [
                    // Each line's categories INCLUDING their ancestors, so a
                    // delivery condition naming "Electronics" matches a product
                    // filed under "Cameras". Expanded here rather than in the
                    // condition: the tree belongs to this module, and the
                    // delivery module goes on knowing nothing about it.
                    'categories' => $this->withAncestors($categories[$product->id] ?? []),
                    'features' => $features,
                ];
            }

            return $lines;
        });

        // The pickers on the delivery method's conditions tab: categories by
        // name, so an admin picks "Ноутбуки" rather than an id.
        hook_listen('shipping.condition.categories', fn (array $options) => $options
            + app(CategoryTree::class)->options());
    }

    /**
     * torgash.exim never appears in this module's manifest requires — these
     * registrations are harmless no-ops if it's never installed, since
     * hook_listen just populates HookBus's internal array and nothing fires
     * unless exim's own code calls hook_filter('exim.*', ...).
     */
    protected function registerEximHooks(): void
    {
        hook_listen('exim.entities', fn (array $v) => $v + [
            'product' => 'Product', 'category' => 'Category', 'feature' => 'Feature',
        ]);

        // The catalogue is what a feed exists for: a marketplace fetches it by address.
        hook_listen('exim.public_entities', fn (array $v) => [...$v, 'product', 'category', 'feature']);

        hook_listen('exim.price_types', fn (array $v) => $v + PriceType::orderBy('sort')->pluck('code', 'id')->all());

        hook_listen('exim.resolve_price_type', fn ($v, array $ctx) => $v ?? PriceType::find($ctx['id']));

        hook_listen('exim.query.product', function ($v, array $ctx) {
            $query = Product::query()->with(['priceTiers', 'priceTypeOverrides']);
            // Filter key stays "enabled_only" so existing export profiles keep
            // working; it now means "listed", so hidden products drop out of
            // feeds exactly like they drop out of the catalogue.
            if ($ctx['filters']['enabled_only'] ?? false) {
                $query->visible();
            }

            return $query;
        });

        hook_listen('exim.query.category', function ($v, array $ctx) {
            $query = Category::query();
            if ($ctx['filters']['enabled_only'] ?? false) {
                $query->listed(); // "listed", as for products just above
            }

            return $query;
        });

        hook_listen('exim.query.feature', fn () => Feature::query());

        // What each export reads, for a file rebuilt when its data changes
        // (Torgash\Exim\Fingerprint) — a module extending a product with
        // tables of its own (stock by warehouse, videos) adds them. A view
        // writes popularity into the products row without moving its
        // `updated_at` (Popularity), and the fingerprint counts on that.
        hook_listen('exim.watch.product', fn (array $tables) => [...$tables,
            'm_catalog_products', 'm_catalog_categories', 'm_catalog_category_product',
            'm_catalog_features', 'm_catalog_feature_values', 'm_catalog_product_features',
            'm_catalog_product_prices', 'm_catalog_price_types', 'm_catalog_product_price_type_overrides',
            'm_catalog_price_type_markup_rules', 'm_catalog_price_type_markup_rule_category',
            'm_catalog_price_type_markup_rule_feature_value', 'm_catalog_labels', 'm_catalog_label_product',
            'm_catalog_availability_statuses', 'media', 'currencies', 'storefronts',
        ]);
        hook_listen('exim.watch.category', fn (array $tables) => [...$tables, 'm_catalog_categories', 'media', 'storefronts']);
        hook_listen('exim.watch.feature', fn (array $tables) => [...$tables,
            'm_catalog_features', 'm_catalog_feature_values', 'm_catalog_feature_groups', 'm_catalog_feature_category',
        ]);

        // Import matching: code/sku/slug are the real keys; a case-insensitive
        // name match is a last-resort fallback the caller should flag, never
        // silently trusted (the gap some engines leave: a code column that the
        // import never actually reads).
        // A product in the bin is still the product the file means — its SKU is
        // taken, so a "new" one could not be written anyway; it comes back
        // (Exim\ProductImport::saving).
        hook_listen('exim.match.product', fn ($v, array $ctx) => $this->namedOrRefused($this->matchBy(
            Product::class, 'sku', $ctx['row']['sku'] ?? null, $ctx['row']['external_id'] ?? null, withTrashed: true,
        ), $ctx['row'], 'no product matches it, and a new one needs a name'));

        // What a file may leave out and a row may not: a new product's and a
        // new category's address, made from the name.
        hook_listen('exim.saving.product', fn (array $ctx) => app(Exim\ProductImport::class)->saving($ctx['model']));
        hook_listen('exim.saving.category', fn (array $ctx) => app(Exim\ProductImport::class)->savingCategory($ctx['model']));
        hook_listen('exim.match.category', fn ($v, array $ctx) => $this->namedOrRefused($this->matchBy(
            Category::class, 'slug', $ctx['row']['slug'] ?? null, $ctx['row']['external_id'] ?? null,
        ), $ctx['row'], 'no category matches it, and a new one needs a name'));
        hook_listen('exim.match.feature', function ($v, array $ctx) {
            $code = $ctx['row']['code'] ?? null;
            if ($code) {
                return $this->namedOrRefused($this->matchBy(Feature::class, 'code', $code), $ctx['row'], 'no characteristic matches it, and a new one needs a name');
            }

            // Any language the file carries a name in, compared in THAT language.
            $names = collect($ctx['row'])->filter(fn ($value, $key) => preg_match('/^name_[a-z]{2,5}$/', (string) $key) && trim((string) $value) !== '');
            if ($names->isEmpty()) {
                return ['model' => new Feature, 'matchedBy' => null, 'refused' => 'no characteristic matches it, and a new one needs a name'];
            }

            $existing = Feature::where(function ($query) use ($names) {
                foreach ($names as $key => $name) {
                    $query->orWhereRaw('lower(name->>?) = lower(?)', [substr($key, 5), trim((string) $name)]);
                }
            })->first();

            return ['model' => $existing ?? new Feature, 'matchedBy' => $existing ? 'name-fallback' : null];
        });

        hook_listen('exim.schema.product', fn (array $fields) => [...$fields,
            // `aliases`: what other systems' files call the column (PLAN §58).
            ['key' => 'sku', 'label' => 'SKU', 'aliases' => ['Product code', 'Артикул', 'Код товара', 'vendorCode'], 'get' => fn (Product $p) => $p->sku, 'set' => fn (Product $p, $v) => $p->sku = $v],
            // The other system's id for this product. Matched on FIRST (see
            // matchBy) and written here, so an existing catalogue acquires its
            // links on the first import that carries them.
            ['key' => 'external_id', 'label' => 'External ID', 'get' => fn (Product $p) => $p->external_id, 'set' => fn (Product $p, $v) => $p->external_id = ($v ?: null)],
            // One declaration per text, one column per language of the shop —
            // exim expands it (`name_en`, `name_uk`, …), so a tenth language
            // needs nothing written here.
            ['key' => 'name', 'label' => 'Name', 'translatable' => true, 'empty' => [], 'aliases' => ['Product name', 'Название', 'Наименование', 'name']],
            ['key' => 'short_description', 'label' => 'Short description', 'translatable' => true, 'group' => 'texts', 'aliases' => ['Short description', 'Краткое описание']],
            // Plain text from another system's file becomes paragraphs: the
            // storefront prints a description as HTML, and a supplier's line
            // breaks would otherwise run together into one block.
            ['key' => 'description', 'label' => 'Description', 'translatable' => true, 'group' => 'texts', 'aliases' => ['Description', 'Описание'],
                'set' => fn (Product $p, $v, string $locale) => \Torgash\Exim\SchemaRegistry::writeTranslation($p, 'description', $locale, Exim\ProductImport::asHtml((string) $v))],
            // The price WITH whatever a customer would get automatically —
            // a sale, a quantity break — and export-only, so a file that goes
            // out and comes back can never bake a promotion into the shop's
            // own number (that one is `base_price`, below, and it is the one
            // with a setter).
            //
            // publicPrice(), not effectivePrice(): an export has no visitor,
            // and an export is run from the admin, where the "visitor" would
            // otherwise be the manager pressing the button — with their own
            // customer group, and any catalog promotion aimed at it. Measured:
            // a manager in a 50% wholesale group exported the catalogue at
            // half price. A sale for EVERYBODY still reaches the file, and
            // should: that one really is what the product costs today.
            ['key' => 'price', 'group' => 'prices', 'label' => 'Price (with discounts, export only)', 'get' => fn (Product $p, array $ctx) => $ctx['priceType'] ? $p->priceForType($ctx['priceType']) : $p->publicPrice(), 'set' => null],
            // What the product can actually be BOUGHT for: its own price plus
            // the cheapest answer to everything it insists on before the buy
            // button works (a server needs a processor). Equal to `price` for
            // an ordinary product, and export-only for the same reason `price`
            // is — it is computed, and a file that comes back must not be able
            // to write a configurator's arithmetic into the shop's own number.
            //
            // A price feed wants THIS one. A marketplace compares what the file
            // says against what the landing page says, and the page has quoted
            // the buildable price since §33.7 — sending the bare one is a
            // mismatch the shop is penalised for, and a click-through that
            // arrives at a dearer product.
            ['key' => 'price_from', 'group' => 'prices', 'label' => 'Price to buy (with required options, export only)', 'get' => fn (Product $p) => app(Pricing\ShownPrice::class)->publicMinimum($p), 'set' => null],
            // Read as the shop's books (which is what every consumer of an
            // export expects), but WRITTEN in the product's own pricing
            // currency — the same rule the inline edit on the list screen
            // follows, because a spreadsheet has no currency column either
            // unless the shop adds the one below. Going through Prices is what
            // keeps the typed number and the books amount from parting company.
            // Before base_price on purpose: a row that changes both must have
            // the currency in hand when the amount is converted.
            ['key' => 'price_currency', 'group' => 'prices', 'label' => 'Price currency', 'get' => fn (Product $p) => $p->price_currency, 'set' => fn (Product $p, $v) => $p->price_currency = ($v ?: null)],
            ['key' => 'base_price', 'group' => 'prices', 'label' => 'Base price', 'get' => fn (Product $p) => $p->price, 'set' => function (Product $p, $v) {
                foreach (app(Prices::class)->columns(['price' => ((int) $v) / 100], $p->price_currency) as $column => $value) {
                    $p->{$column} = $value;
                }
            }],
            // The struck-through price. A marketplace file wants the pair —
            // what it costs now and what it was — and until now only the first
            // half could leave: this is the number the storefront strikes
            // through, and a supplier's RRP is a thing shops genuinely import.
            //
            // Writable, unlike `price`, because it is the shop's OWN number
            // rather than a computed one; same currency rule as base_price
            // (read as the books, written in the product's pricing currency),
            // and it must stay after `price_currency` for the same reason.
            // Empty stays empty: no RRP is a different answer from an RRP of
            // zero, and oldPrice() reads it that way.
            //
            // Cost price is deliberately still absent: an export is the one
            // file most likely to be handed to an outsider, and nothing in a
            // price feed needs to know what the shop paid.
            ['key' => 'list_price', 'group' => 'prices', 'label' => 'List price (RRP)', 'get' => fn (Product $p) => $p->list_price, 'set' => function (Product $p, $v) {
                $typed = ($v === '' || $v === null) ? null : ((int) $v) / 100;

                foreach (app(Prices::class)->columns(['list_price' => $typed], $p->price_currency) as $column => $value) {
                    $p->{$column} = $value;
                }
            }],
            ['key' => 'stock', 'group' => 'stock', 'label' => 'Stock', 'aliases' => ['Quantity', 'Количество', 'Остаток', 'count'], 'get' => fn (Product $p) => $p->stock, 'set' => fn (Product $p, $v) => $p->stock = (int) $v],
            // `enabled` predates the three-state status and is kept as a
            // lossy boolean view of it, because export profiles already in the
            // wild list this column by name — dropping it would break them
            // silently. It reads false only for "disabled"; import maps
            // true → active, false → disabled and can never produce "hidden".
            // Round-tripping the real value is what the `status` field below
            // is for.
            ['key' => 'enabled', 'label' => 'Enabled (legacy boolean)', 'get' => fn (Product $p) => $p->status !== Product::STATUS_DISABLED, 'set' => fn (Product $p, $v) => $p->status = filter_var($v, FILTER_VALIDATE_BOOLEAN) ? Product::STATUS_ACTIVE : Product::STATUS_DISABLED],
            // Some engines write the status as one letter: A, H, D.
            ['key' => 'status', 'label' => 'Status (active/hidden/disabled)', 'aliases' => ['Status'], 'get' => fn (Product $p) => $p->status, 'set' => fn (Product $p, $v) => $p->status = match (true) {
                in_array($v, Product::STATUSES, true) => $v,
                strtoupper(trim((string) $v)) === 'A' => Product::STATUS_ACTIVE,
                strtoupper(trim((string) $v)) === 'H' => Product::STATUS_HIDDEN,
                default => Product::STATUS_DISABLED,
            }],
            ['key' => 'slug', 'label' => 'Slug', 'aliases' => ['SEO name'], 'get' => fn (Product $p) => $p->slug, 'set' => fn (Product $p, $v) => $p->slug = $v],
            // The date "Newest first" sorts by, which is why it is settable
            // here at all: a catalogue of phones imported in one afternoon
            // would otherwise claim the iPhone 10 is as new as the 17, and the
            // file is where their release dates are. Empty leaves it alone —
            // on a new row Eloquent stamps it, on an existing one the shop's
            // own date is not something an import may quietly forget.
            ['key' => 'created_at', 'label' => 'Created at', 'get' => fn (Product $p) => $p->created_at?->toDateTimeString(), 'set' => function (Product $p, $v) {
                if (trim((string) $v) !== '') {
                    $p->created_at = Carbon::parse($v);
                }
            }],
            // Sorting again, and hand-editable for the same reason it is in
            // the form: the shop may want a product higher than its own
            // history puts it. `views_count`/`sales_count` are statistics and
            // stay out of the schema.
            ['key' => 'popularity', 'label' => 'Popularity', 'aliases' => ['Popularity'], 'get' => fn (Product $p) => $p->popularity, 'set' => fn (Product $p, $v) => $p->popularity = max(0, (int) $v)],
            // Canonical units in the file, like base_price is minor units: a
            // spreadsheet that travels between shops must not change meaning
            // because the two shops type weights in different units. Empty
            // stays empty — "not measured" is a value the shipping engine reads.
            ['key' => 'weight_g', 'group' => 'stock', 'label' => 'Weight (g)', 'get' => fn (Product $p) => $p->weight, 'set' => fn (Product $p, $v) => $p->weight = $v === '' || $v === null ? null : (int) $v],
            ['key' => 'length_mm', 'group' => 'stock', 'label' => 'Length (mm)', 'get' => fn (Product $p) => $p->length, 'set' => fn (Product $p, $v) => $p->length = $v === '' || $v === null ? null : (int) $v],
            ['key' => 'width_mm', 'group' => 'stock', 'label' => 'Width (mm)', 'get' => fn (Product $p) => $p->width, 'set' => fn (Product $p, $v) => $p->width = $v === '' || $v === null ? null : (int) $v],
            ['key' => 'height_mm', 'group' => 'stock', 'label' => 'Height (mm)', 'get' => fn (Product $p) => $p->height, 'set' => fn (Product $p, $v) => $p->height = $v === '' || $v === null ? null : (int) $v],
            ...$this->priceTypeFields(),
            ...$this->seoFields(),
            // Another system's columns: a category path, a brand, photo links,
            // characteristics — import only (Exim\ProductImport).
            ...app(Exim\ProductImport::class)->fields(),
            // What a marketplace feed's template prints (Exim\ProductFeed).
            ...app(Exim\ProductFeed::class)->fields(),
        ]);

        // The shop around a product feed's offers: name, currencies, categories.
        hook_listen('exim.template.context', fn (array $context, array $ctx) => $ctx['entity'] === 'product'
            ? app(Exim\ProductFeed::class)->context($context, $ctx['profile']->locales ?: null)
            : $context);

        hook_listen('exim.schema.category', fn (array $fields) => [...$fields,
            ['key' => 'slug', 'label' => 'Slug', 'get' => fn (Category $c) => $c->slug, 'set' => fn (Category $c, $v) => $c->slug = $v],
            ['key' => 'external_id', 'label' => 'External ID', 'get' => fn (Category $c) => $c->external_id, 'set' => fn (Category $c, $v) => $c->external_id = ($v ?: null)],
            ['key' => 'name', 'label' => 'Name', 'translatable' => true, 'empty' => []],
            ['key' => 'description', 'label' => 'Description', 'translatable' => true, 'group' => 'texts'],
            ['key' => 'enabled', 'label' => 'Enabled (legacy boolean)', 'get' => fn (Category $c) => $c->status !== Category::STATUS_DISABLED, 'set' => fn (Category $c, $v) => $c->status = filter_var($v, FILTER_VALIDATE_BOOLEAN) ? Category::STATUS_ACTIVE : Category::STATUS_DISABLED],
            ['key' => 'status', 'label' => 'Status (active/hidden/disabled)', 'get' => fn (Category $c) => $c->status, 'set' => fn (Category $c, $v) => $c->status = in_array($v, Category::STATUSES, true) ? $v : Category::STATUS_DISABLED],
            ...$this->seoFields(),
        ]);

        hook_listen('exim.schema.feature', fn (array $fields) => [...$fields,
            ['key' => 'code', 'label' => 'Code', 'get' => fn (Feature $f) => $f->code, 'set' => fn (Feature $f, $v) => $f->code = $v],
            ['key' => 'name', 'label' => 'Name', 'translatable' => true, 'empty' => []],
            // `info` is a spelling this column no longer has — it was never
            // about what a characteristic IS, only about where it was drawn —
            // so a file written before the split still imports, as an ordinary
            // characteristic whose places the `shown_in` column beside it says.
            ['key' => 'purpose', 'label' => 'Purpose', 'get' => fn (Feature $f) => $f->purpose, 'set' => fn (Feature $f, $v) => $f->purpose = $v === 'info' ? 'filter' : $v],
            ['key' => 'input_type', 'label' => 'Input type', 'get' => fn (Feature $f) => $f->input_type, 'set' => fn (Feature $f, $v) => $f->input_type = $v],
            // Where it is SHOWN, as the place keys themselves: an empty cell
            // means nowhere, which is a real answer and not the same as a
            // column the file does not carry at all.
            ['key' => 'shown_in', 'label' => 'Shown in', 'get' => fn (Feature $f) => implode(',', (array) ($f->shown_in ?? Features\Places::DEFAULT)), 'set' => fn (Feature $f, $v) => $f->shown_in = array_values(array_intersect(
                app(Features\Places::class)->keys(),
                array_map(fn ($k) => trim((string) $k), explode(',', (string) $v)),
            ))],
        ]);
    }

    /**
     * The four kinds of page the catalogue owns, for whoever is auditing the
     * shop's metadata (`torgash.seo`).
     *
     * Answered through a hook and nothing else: the catalogue does not name a
     * class of that module, and with it uninstalled this listener costs one
     * unread entry in a filter. The reverse dependency would have been the SEO
     * module holding a list of every entity in the shop, which is exactly what
     * the registry exists to avoid.
     */
    protected function seoSubjects(): void
    {
        hook_listen('seo.subjects', fn (array $subjects) => $subjects + [
            'product' => [
                'label' => 'Products',
                'query' => fn () => Product::query()->orderByDesc('id'),
                'title' => fn (Product $p) => $p->t('name') ?: $p->sku,
                'url' => fn (Product $p) => $p->slug ? url('/product/'.$p->slug) : null,
                'edit_url' => fn (Product $p) => route('admin.catalog.products.edit', $p),
            ],
            'category' => [
                'label' => 'Categories',
                'query' => fn () => Category::query()->orderBy('parent_id')->orderBy('sort'),
                'title' => fn (Category $c) => $c->t('name') ?: $c->slug,
                // For a picker rather than a listing: the SEO module's scope
                // select needs the tree, and it may not name this module's
                // classes to get it. The audit screen keeps using `title`.
                'options' => fn () => app(CategoryTree::class)->options(),
                'url' => fn (Category $c) => $c->slug ? url('/catalog/'.$c->slug) : null,
                'edit_url' => fn (Category $c) => route('admin.catalog.categories.edit', $c),
            ],
            // A filter page is a page like any other, so its titles are written
            // by the same templates — which is the whole reason the rules that
            // make them carry no template engine of their own.
            'filter_page' => [
                'label' => 'Filter pages',
                'query' => fn () => FilterPage::with('category')->whereNull('place_id')
                    ->orderBy('category_id')->orderBy('position'),
                'title' => fn (FilterPage $p) => $p->title(),
                'url' => fn (FilterPage $p) => $p->category ? $p->url() : null,
                'edit_url' => fn (FilterPage $p) => route('admin.catalog.filter-pages.edit', $p),
            ],
            // The same table, listed apart — because the two are not the same
            // sentence. A shop writes "{category} в {location:6}" for one and
            // "{filter} {category|lower}" for the other, and a single subject
            // would have made them share a template.
            'geo_page' => [
                'label' => 'City pages',
                'query' => fn () => FilterPage::with('category')->whereNotNull('place_id')
                    ->orderBy('category_id')->orderBy('position'),
                'title' => fn (FilterPage $p) => $p->title(),
                'url' => fn (FilterPage $p) => $p->category ? $p->url() : null,
                'edit_url' => fn (FilterPage $p) => route('admin.catalog.filter-pages.edit', $p),
            ],
            // Only the values that actually HAVE a page: a brand without a slug
            // is a characteristic value like any other and has no address to
            // write a title for.
            'brand' => [
                'label' => 'Brands',
                'query' => fn () => FeatureValue::whereNotNull('slug')
                    ->whereHas('feature', fn ($q) => $q->where('purpose', 'brand')),
                'title' => fn (FeatureValue $v) => $v->display(),
                'url' => fn (FeatureValue $v) => url('/brand/'.$v->slug),
                'edit_url' => fn (FeatureValue $v) => route('admin.catalog.features.values.edit', [$v->feature_id, $v]),
            ],
        ]);
    }

    /**
     * A product's categories plus every category above them.
     *
     * @param  iterable<int>  $ids
     * @return list<int>
     */
    protected function withAncestors(iterable $ids): array
    {
        $tree = app(CategoryTree::class);
        $all = [];

        foreach ($ids as $id) {
            $all[(int) $id] = true;

            foreach ($tree->ancestors((int) $id) as $ancestor) {
                $all[$ancestor] = true;
            }
        }

        return array_map('intval', array_keys($all));
    }

    /**
     * One column per price type the shop has (not the base one): the price
     * THIS product is given for that type by hand — what 1C sends as a price
     * of its own — in minor units of the product's price currency, like
     * `base_price`. An empty cell REMOVES it, and the type goes back to
     * working it out (its markup rule or percentage); that is the difference
     * between "wholesale is -20%" and "wholesale for this one is 800".
     *
     * Written after the product is saved, since it is a row of its own. The
     * computed price for a type stays `price` under the profile's chosen type.
     */
    protected function priceTypeFields(): array
    {
        return PriceType::query()->where('is_base', false)->orderBy('id')->get()->map(fn (PriceType $type) => [
            'key' => 'price_type:'.$type->code,
            'label' => 'Own price ('.$type->code.')',
            'base_label' => 'Own price',
            'suffix' => $type->code,
            'group' => 'prices',
            'get' => fn (Product $p) => $p->priceTypeOverrides->firstWhere('price_type_id', $type->id)?->typedPrice(),
            'set' => null,
            'after' => function (Product $p, $v) use ($type) {
                if (trim((string) $v) === '') {
                    ProductPriceTypeOverride::where('product_id', $p->id)->where('price_type_id', $type->id)->delete();

                    return;
                }

                ProductPriceTypeOverride::updateOrCreate(
                    ['product_id' => $p->id, 'price_type_id' => $type->id],
                    app(Prices::class)->row(((int) $v) / 100, $p->price_currency),
                );
            },
        ])->values()->all();
    }

    /**
     * The SEO columns, for any entity that carries them — a spreadsheet is how
     * a shop fills five hundred meta descriptions, and the audit screen is how
     * it fills five.
     *
     * Translatable, like the name: exim writes one column per language of the
     * shop, since a CSV has no room for a jsonb object and the columns are what
     * a translator is handed. Writing one language leaves the others alone, and
     * a blank cell CLEARS that language rather than storing an empty string —
     * "" and "not stated" have to be the same thing, exactly as on the form
     * (App\Core\Seo\MetaFields).
     *
     * @return list<array{key: string, label: string, get: callable, set: ?callable}>
     */
    protected function seoFields(): array
    {
        $fields = [];

        foreach (['meta_title' => 'Meta title', 'meta_description' => 'Meta description', 'h1' => 'Heading (H1)'] as $column => $label) {
            $fields[] = ['key' => $column, 'label' => $label, 'translatable' => true, 'group' => 'seo',
                // Another engine's name for the same column.
                'aliases' => $column === 'meta_title' ? ['Page title'] : []];
        }

        $fields[] = ['key' => 'noindex', 'group' => 'seo', 'label' => 'Keep out of search engines', 'get' => fn (Model $m) => $m->noindex, 'set' => fn (Model $m, $v) => $m->noindex = filter_var($v, FILTER_VALIDATE_BOOLEAN)];
        $fields[] = ['key' => 'canonical_url', 'group' => 'seo', 'label' => 'Canonical address', 'get' => fn (Model $m) => $m->canonical_url, 'set' => fn (Model $m, $v) => $m->canonical_url = (trim((string) $v) ?: null)];

        return $fields;
    }

    /**
     * A row that matches nothing and carries no name cannot make a record —
     * every catalogue table requires one — so the match says why (`refused`,
     * an English sentence) and the import skips that row with a note. It used
     * to reach the database's NOT NULL and roll the whole file back: one
     * mistyped SKU in a price list cost every other row of it.
     */
    protected function namedOrRefused(array $match, array $row, string $reason): array
    {
        if ($match['model']->exists) {
            return $match;
        }

        foreach ($row as $key => $value) {
            if (preg_match('/^name_[a-z]{2,5}$/', (string) $key) && trim((string) $value) !== '') {
                return $match;
            }
        }

        return $match + ['refused' => $reason];
    }

    /** @return array{model: Model, matchedBy: ?string} */
    /**
     * The row this import line is about: the OTHER system's id first, our own
     * key second.
     *
     * That order is the whole point of `external_id`. A sku and a slug are the
     * shop's own keys and the shop changes them; the foreign id does not move,
     * because it belongs to the system doing the exchanging. Matching on the
     * natural key alone means the first renamed sku arrives as a second product.
     *
     * A line carrying an external id nobody has yet still matches by sku — and
     * the schema field then writes the id onto it, which is how an existing
     * catalogue acquires its links on the first import instead of needing a
     * migration nobody could write.
     */
    protected function matchBy(string $modelClass, string $column, ?string $value, ?string $externalId = null, bool $withTrashed = false): array
    {
        $query = fn () => $withTrashed ? $modelClass::withTrashed() : $modelClass::query();

        if ($externalId) {
            $existing = $query()->where('external_id', $externalId)->first();

            if ($existing) {
                return ['model' => $existing, 'matchedBy' => 'external_id'];
            }
        }

        if (! $value) {
            return ['model' => new $modelClass, 'matchedBy' => null];
        }

        $existing = $query()->where($column, $value)->first();

        return ['model' => $existing ?? new $modelClass, 'matchedBy' => $existing ? $column : null];
    }
}
