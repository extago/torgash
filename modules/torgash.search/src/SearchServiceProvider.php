<?php

namespace Torgash\Search;

use App\Core\Modules\ModuleLifecycle;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;

/**
 * The search engine (PLAN §34), wired in:
 * - the sources it asks (`search.retrievers` — text; typos are not a source
 *   but a reading of the query, corrected by the catalogue's `Dictionary`);
 * - the catalogue's results page, which asks `catalog.search` and falls back to
 *   its own query when this module is absent;
 * - a "search" place on characteristics (`catalog.feature.places`), which is
 *   how a shop marks one as searchable in the characteristic's own form;
 * - keeping documents current: a save MARKS a product (`Pending`) and the
 *   documents are rebuilt once, when the request ends.
 */
class SearchServiceProvider extends ServiceProvider implements ModuleLifecycle
{
    public function register(): void
    {
        $this->app->singleton(Pending::class);
        $this->app->singleton(Indexer::class);
        $this->app->singleton(Dictionary::class);
        $this->app->singleton(Speller::class);
        $this->app->singleton(Rules::class);
        $this->app->singleton(Stats::class);
        \App\Core\Support\PerRequest::forget($this->app, Rules::class);
    }

    public function boot(): void
    {
        // Its card on core's admin dashboard.
        app(Admin\DashboardWidgets::class)->register();

        hook_listen('search.retrievers', fn (array $all) => $all + [
            'text' => ['class' => Retrievers\TextRetriever::class, 'weight' => 1.0],
            // A phrase's products lead: no text match can reach this weight.
            'pinned' => ['class' => Retrievers\PinnedRetriever::class, 'weight' => 10.0],
            // What people choose for this query: enough to reorder close
            // matches, never to outrank a far better one.
            'clicks' => ['class' => Retrievers\ClicksRetriever::class, 'weight' => 0.5],
        ]);

        // Answered as plain data: the catalogue must not know this module's
        // classes — it keeps its own query for a shop without it.
        hook_listen('catalog.search', function ($answer, array $context) {
            if ($answer !== null) {
                return $answer;
            }

            $q = (string) ($context['q'] ?? '');
            $category = ($slug = (string) ($context['category'] ?? '')) !== ''
                ? \Torgash\Catalog\Models\Category::where('slug', $slug)->reachable()->first()
                : null;

            $scope = Scope::current();
            $withCatalog = Scope::withCatalog($scope);
            $result = $withCatalog
                ? app(Engine::class)->search($q, $category?->id)
                : new SearchResult(new SearchQuery($q), []);
            app(History::class)->remember($q);

            // The first page is the search; the next ones are the same one.
            // Counted only where the catalogue was asked: "found nothing" is
            // a statement about products.
            if ($withCatalog && (int) ($context['page'] ?? 1) <= 1) {
                app(Stats::class)->searched($result, array_slice($result->ids, 0, 200), request());
            }
            // The rest of the site, when that is where the visitor looks — the
            // same sections the window shows, as plain data; first page only.
            $sections = $scope === Scope::SITE && (int) ($context['page'] ?? 1) <= 1
                ? Http\SuggestController::sections($q, $result) : [];

            // One product, found as typed, on the first page: its page is the
            // answer. Never after reading the query on another layout or as a
            // typo — sending somebody straight to a guess is worse than showing it.
            $redirect = setting('torgash.search', 'redirect_single')
                && $result->how === null && $result->didYouMean === null
                && $sections === []
                && $result->total() === 1 && (int) ($context['page'] ?? 1) <= 1
                ? (($only = Product::find($result->ids[0])) ? route('catalog.product', $only->slug) : null)
                : null;

            return array_filter([
                'ids' => $result->ids,
                'read_as' => $result->readAs,
                'how' => $result->how,
                'did_you_mean' => $result->didYouMean,
                'sections' => $sections ?: null,
                'redirect' => $redirect,
                'category' => $category ? ['name' => $category->t('name'), 'slug' => $category->slug] : null,
            ], fn ($v) => $v !== null) + ['ids' => []];
        });

        hook_listen('catalog.feature.places', fn (array $places) => $places + [
            'search' => [
                'label' => 'Search',
                'help' => 'Typing one of its values finds the product. A brand is always searched.',
            ],
        ]);

        $this->keepCurrent();
        $this->statistics();
        $this->liveWindow();

        Route::middleware(['web', 'auth', 'can:search.manage'])
            ->prefix('admin/search-engine')
            ->name('admin.search-engine.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        if ($this->app->runningInConsole()) {
            $this->commands([Console\ReindexCommand::class]);
        }
    }

    /**
     * Counting: a product page opened while it was an answer on screen is a
     * click (the catalogue's `catalog.product.view`, which every product page
     * passes through), and what the shop no longer keeps goes nightly.
     */
    protected function statistics(): void
    {
        hook_listen('catalog.product.view', function ($product) {
            if ($product instanceof Product) {
                app(Stats::class)->opened((int) $product->id, request());
            }

            return $product;
        });

        hook_listen('core.schedule', function (array $context) {
            $context['schedule']->call(fn () => app(Stats::class)->prune())->daily()->name('search:prune-stats');

            // A product that reached the table without its model's events has
            // no document and cannot be found; the net under every such door.
            $context['schedule']->call(function () {
                $indexer = app(Indexer::class);
                $indexer->products($indexer->missing());
            })->daily()->name('search:index-missing');
        });
    }

    /** Every door that changes what a document says marks the products it touched. */
    protected function keepCurrent(): void
    {
        $pending = fn () => app(Pending::class);

        Product::saved(fn (Product $p) => $pending()->mark($p->id));
        // The document goes by cascade; its words leave the dictionary first.
        Product::forceDeleting(fn (Product $p) => app(Indexer::class)->forget($p->id));
        ProductFeature::saved(fn (ProductFeature $pf) => $pending()->mark((int) $pf->product_id));
        ProductFeature::deleted(fn (ProductFeature $pf) => $pending()->mark((int) $pf->product_id));

        // A value renamed, a characteristic marked searchable or made a brand:
        // every product carrying it says something different now.
        FeatureValue::saved(function (FeatureValue $value) use ($pending) {
            if ($value->wasChanged('value') || $value->wasRecentlyCreated) {
                $pending()->mark(DB::table('m_catalog_product_features')->where('feature_value_id', $value->id)->pluck('product_id')->all());
            }
        });

        Feature::saved(function (Feature $feature) use ($pending) {
            if ($feature->wasChanged(['shown_in', 'purpose'])) {
                $pending()->mark(DB::table('m_catalog_product_features')->where('feature_id', $feature->id)->pluck('product_id')->all());
            }
        });

        hook_listen('core.setting.changed', function (array $context) use ($pending) {
            if ($context['module'] === 'torgash.search' && $context['key'] === 'search_descriptions') {
                $pending()->markEverything();
            }
        });

        $this->app->terminating(fn () => app(Pending::class)->flush());

        Event::listen([JobProcessed::class, JobFailed::class], fn () => app(Pending::class)->flush());
    }

    /**
     * The window while typing: its route (in every locale shape, so it
     * answers in the page's language), a limit — a public address asked on
     * every pause in typing is the first thing a scraper finds — its script
     * on every storefront page, and no place in an index.
     */
    protected function liveWindow(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('torgash-search', fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by($request->ip()));

        \App\Core\Storefronts\StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        hook_listen('core.seo.private_routes', fn (array $names) => [...$names, 'search.*']);

        hook_listen('storefront.search.controls', fn (string $html) => Scope::switchable() && \Illuminate\Support\Facades\Route::has('search.suggest')
            ? $html.view('torgash.search::suggest.scope', ['scope' => Scope::current()])->render()
            : $html);

        hook_listen('core.footer', function (string $html) {
            if (! \Illuminate\Support\Facades\Route::has('search.suggest')) {
                return $html;
            }

            return $html.view('torgash.search::suggest.script', [
                'url' => route('search.suggest'),
                'forget' => route('search.recent.forget'),
                'delay' => max(0, (int) setting('torgash.search', 'delay_ms')),
                'min' => max(1, (int) setting('torgash.search', 'min_chars')),
                'backdrop' => (bool) setting('torgash.search', 'suggest_backdrop'),
            ])->render();
        });
    }

    /** Build every document now: search answers nothing until they exist. */
    public function install(): void
    {
        app(Indexer::class)->all();
    }

    /** The table goes with the migration; nothing lives outside it. */
    public function uninstall(): void {}
}
