<?php

namespace Torgash\Warehouses;

use App\Core\Storefronts\StorefrontRoutes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Models\Product;
use Torgash\Warehouses\Models\Warehouse;

class WarehousesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StockAllocator::class);
        // Per-request memo, never a static: under a persistent worker a static
        // would hand one visitor's chosen shop to the next (CLAUDE.md).
        $this->app->singleton(PointStore::class);
        $this->app->singleton(Transfers::class);
        \App\Core\Support\PerRequest::forget($this->app, Transfers::class);
    }

    /**
     * The memo-holding singletons are resolved inside each listener, never
     * captured once here: a hook listener registered at boot outlives the
     * request under a persistent worker, and a closure holding the allocator
     * would go on answering from the previous visitor's memo — the same trap as
     * a `static` property, wearing a different hat (CLAUDE.md).
     */
    public function boot(): void
    {
        // Its part of the demo shop (PLAN §55.3), after the catalogue's products.
        hook_listen('core.demo.parts', fn (array $parts) => $parts + [
            'warehouses' => ['label' => 'Warehouses', 'after' => ['catalog'], 'install' => fn (\App\Core\Demo\DemoPack $pack) => app(Demo\WarehousesDemo::class)->install($pack)],
        ]);

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        $this->registerStorefrontPoint();

        // "Our stores" — a block for the footer (or anywhere), drawn from the
        // public warehouses. A soft dependency: no blocks module, no block.
        if (class_exists(\Torgash\Blocks\BlockTypes::class)) {
            \Torgash\Blocks\BlockTypes::register('stores', [
                'description' => 'Your shops and pick-up points by town, with the address and the opening hours — from the warehouses marked as public.',
                'fits' => ['wide'],
                'label' => 'Our stores',
                'icon' => 'store',
                'view' => 'torgash.warehouses::blocks.stores',
                'schema' => [
                    ['key' => 'heading', 'type' => 'text', 'translatable' => true, 'label' => 'Heading over each town',
                        'help' => 'With :city for the town, e.g. "Stores in :city". Empty — the name of the town alone.'],
                ],
            ]);
        }

        // What must be true about stock, for whoever is watching. A filter
        // nobody fires when `torgash.guard` is not installed, so this is a
        // statement about our own data rather than a dependency on theirs.
        hook_listen('guard.invariants', fn (array $all) => $all
            + (new Invariants\WarehouseInvariants)->register());

        // Product::adjustStock() calls this with null; a non-null return means
        // "I handled it" and the plain aggregate column update is skipped.
        hook_listen('catalog.product.stock_adjust', function ($value, array $context) {
            /** @var Product $product */
            $product = $context['product'];

            return app(StockAllocator::class)->adjust(
                $product->id,
                $context['delta'],
                $context['restore'] ?? null,
                isset($context['prefer']) ? (int) $context['prefer'] : null,
            ) ?? $value;
        });

        // "Where can goods be put?" — asked by anything that has units in its
        // hand and a choice about where they land: a returned parcel, today.
        // A LIST rather than a model, so the asker (torgash.rma) never learns
        // this module's classes and simply gets an empty picker without it.
        hook_listen('catalog.stock.warehouses', fn (array $value) => $value + Warehouse::orderBy('sort')->orderBy('id')->get()
            ->mapWithKeys(fn (Warehouse $w) => [$w->id => $w->t('name')])
            ->all());

        // "Which single warehouse could fill this whole order?" — asked by
        // checkout before it takes anything, so an order is not scattered
        // across three warehouses (and three parcels) when one could send it.
        // A shop without this module gets null and the old behaviour.
        hook_listen('catalog.stock.prefer_warehouse', function ($value, array $context) {
            return $value ?? app(StockAllocator::class)->covering((array) ($context['lines'] ?? []));
        });

        // A listing announces its whole product set before rendering rows, so
        // the per-row hooks below cost one query for the page instead of one
        // each. Purely an optimisation: every hook still works unprimed.
        hook_listen('catalog.products.listed', function (array $context) {
            $ids = collect($context['products'] ?? [])->pluck('id')->filter();
            app(StockAllocator::class)->prime($ids);
            app(Transfers::class)->prime($ids);
        });

        // Stock by warehouse decides "can be ordered" too, so a product feed
        // that rebuilds on a change of its data watches it (torgash.exim).
        hook_listen('exim.watch.product', fn (array $tables) => [...$tables, 'm_warehouses', 'm_warehouse_product_stock']);

        // Where a parcel leaves from, for a carrier that prices by distance.
        // Answered with a place id rather than a delivery object on purpose:
        // this module knows about the catalogue, not about shipping, and a
        // shop with the shipping module uninstalled must still be able to say
        // where its warehouse is. Today it is the dispatching warehouse for
        // the whole cart; when a cart can be split across origins the same
        // hook answers per shipment.
        hook_listen('shipping.origin', function ($value, array $context) {
            if ($value !== null) {
                return $value;
            }

            $placeId = Warehouse::dispatching()?->place_id;

            return $placeId ? ['place_id' => $placeId] : $value;
        });

        // "Where can this cart be collected in that town?" — asked by the
        // delivery module's pickup method, answered with the shops that are
        // marked for collection AND actually hold the whole order.
        //
        // The coverage check belongs here, while the list is built, not at
        // deduction time: offering a point that has two of the three items is
        // a promise the shop cannot keep, and the customer would only find out
        // after ordering (PLAN §20.2/3).
        hook_listen('shipping.pickup_points', function (array $value, array $context) {
            $placeId = (int) ($context['place_id'] ?? 0);

            if ($placeId <= 0) {
                return $value;
            }

            $points = Warehouse::pickup()->where('place_id', $placeId)->orderBy('sort')->orderBy('id')->get();

            if ($points->isEmpty()) {
                return $value;
            }

            $lines = (array) ($context['lines'] ?? []);

            // No cart in front of us (the admin's zone tester) — then coverage
            // is not a question that can be asked, and every point is listed.
            if ($lines !== []) {
                $covering = array_flip(app(StockAllocator::class)->coveringAmong($lines, $points->pluck('id')->all()));
                $points = $points->filter(fn (Warehouse $point) => isset($covering[$point->id]));
            }

            return array_merge($value, $points->map(fn (Warehouse $point) => [
                'id' => $point->id,
                'name' => $point->t('name'),
                'address' => $point->address,
                'phone' => $point->phone,
                'hours' => $point->hoursSummary(),
            ])->values()->all());
        });

        hook_listen('catalog.product.stock_locked', function (bool $value, array $context) {
            /** @var Product $product */
            $product = $context['product'];

            // A product still being created (no id yet) can't have warehouse
            // rows — both hooks fire unconditionally from the form, unlike
            // stock_adjust, which only ever runs against a saved product.
            return $product->id !== null && ($value || app(StockAllocator::class)->isSplit($product->id));
        });

        hook_listen('catalog.product.stock_extra', function (string $value, array $context) {
            /** @var Product $product */
            $product = $context['product'];

            if ($product->id === null || ! app(StockAllocator::class)->isSplit($product->id)) {
                return $value;
            }

            return $value.view('torgash.warehouses::partials.stock-breakdown', ['product' => $product])->render();
        });

        // Storefront-facing, optional by construction: fires only for a
        // split product, and only ever renders something if the module is
        // installed at all — catalog.product.availability is seeded ''.
        hook_listen('catalog.product.availability', function (string $value, array $context) {
            /** @var Product $product */
            $product = $context['product'];

            if (! app(StockAllocator::class)->isSplit($product->id)) {
                return $value;
            }

            $point = app(PointStore::class)->current();

            return $value.view('torgash.warehouses::partials.storefront-availability', [
                'product' => $product,
                // The shop the customer chose leads, because it is the only row
                // they are actually going to drive to.
                'point' => $point,
                'here' => $here = ($point ? app(StockAllocator::class)->qtyAt($point->id, $product->id) : null),
                'bringBy' => $point && ! $here && $product->isOrderable() && setting('torgash.warehouses', 'transfer_date')
                    ? app(Transfers::class)->date($product->id, $point->id)
                    : null,
            ])->render();
        });

        // Under "Pickup" in the product page's delivery block (torgash.shipping):
        // the one shelf a customer who chose a shop is asking about — there
        // today, or by when another warehouse brings it. Nothing when no shop
        // is chosen: the delivery block is about the town then.
        hook_listen('shipping.product_delivery.note', function (string $value, array $context) {
            $point = app(PointStore::class)->current();
            $option = $context['option'] ?? null;
            $product = $context['product'] ?? null;

            if (! $point || ! $product instanceof Product || ($option->method->provider ?? null) !== 'pickup') {
                return $value;
            }

            $here = app(StockAllocator::class)->qtyAt($point->id, $product->id);
            $bringBy = ! $here && $product->isOrderable() && setting('torgash.warehouses', 'transfer_date')
                ? app(Transfers::class)->date($product->id, $point->id)
                : null;

            // Counted from today: a page kept ready for guests (PLAN §61)
            // that prints it is kept until tomorrow at the latest.
            if ($bringBy !== null) {
                page_cache()->until(\Carbon\CarbonImmutable::tomorrow());
            }

            return $value.match (true) {
                $here > 0 => '<span class="text-success">'.e(__('In stock at :point', ['point' => $point->t('name')])).'</span>',
                $bringBy !== null => '<span class="text-primary">'.e(__('We will bring it to :point by :date', ['point' => $point->t('name'), 'date' => $bringBy->translatedFormat('j F')])).'</span>',
                default => '',
            };
        });

        Route::middleware(['web', 'auth', 'can:warehouses.manage'])
            ->prefix('admin/warehouses')
            ->name('admin.warehouses.')
            ->group(dirname(__DIR__).'/routes-admin.php');
    }

    /**
     * The storefront seen through one of the shop's points (PLAN §20.3).
     *
     * All three listeners are no-ops until a visitor actually picks a shop, so
     * a shop with no points — or one whose customer has not chosen — behaves
     * exactly as it did before any of this.
     */
    protected function registerStorefrontPoint(): void
    {
        // The shop a visitor chose changes what a card says about stock and,
        // with `only`, which products are listed at all: every page kept
        // ready for guests is filed under it (PLAN §61). A sale moves a
        // warehouse's row, which announces itself as a stock movement.
        page_cache()
            ->vary('pickup', fn () => (string) app(PointStore::class)->id().(app(PointStore::class)->filtering() ? '+only' : ''),
                session: ['warehouses_point', 'warehouses_point_only'])
            ->unseen('m_warehouse_product_stock');

        // The picker itself, beside the search box where every shop that has
        // branches puts it — unless the town window is there (torgash.locations,
        // PLAN §38), which asks for the town and the shop in one place: two
        // controls answering one question is one too many.
        hook_listen('storefront.header.after_search', function (string $value) {
            if (Route::has('locations.dialog')) {
                return $value;
            }

            $points = Warehouse::pickup()->orderBy('sort')->orderBy('id')->get();

            if ($points->isEmpty()) {
                return $value;
            }

            return $value.view('torgash.warehouses::partials.point-picker', [
                'points' => $points,
                'chosen' => app(PointStore::class)->current(),
                // Named, not numbered: the picker groups by town, and the town
                // registry is the only thing that can turn an id into a name.
                'towns' => (array) hook_filter('locations.place.names', [], [
                    'ids' => $points->pluck('place_id')->filter()->unique()->all(),
                ]),
                // Where the visitor already said they are, so their own town's
                // shops are the ones open in front of them.
                'here' => hook_filter('storefront.visitor_place', null),
            ])->render();
        });

        // What a card says about stock, once a shop has been chosen: whether it
        // is on THAT shelf. Everything a card shows about availability is a
        // promise about where the customer is going.
        hook_listen('catalog.product.stock_line', function ($value, array $context) {
            $point = app(PointStore::class)->current();

            if (! $point) {
                return $value;
            }

            /** @var Product $product */
            $product = $context['product'];
            $here = app(StockAllocator::class)->qtyAt($point->id, $product->id);

            // null: this product's stock is not split by warehouse at all, so
            // it is available wherever the rest is — the shop's ordinary answer
            // is the right one.
            if ($here === null) {
                return $value;
            }

            return view('torgash.warehouses::partials.card-stock', [
                'product' => $product,
                'point' => $point,
                'here' => $here,
                'bringBy' => $here <= 0 && setting('torgash.warehouses', 'transfer_date')
                    ? app(Transfers::class)->date($product->id, $point->id)
                    : null,
            ])->render();
        });

        // "The storefront IS this point's assortment" — the other half of the
        // setting, and the half a shop opts into. Products with no per-warehouse
        // rows are never hidden: their stock is the plain column, which means
        // available everywhere, not absent here.
        hook_listen('catalog.products.listed_query', function ($query) {
            $point = app(PointStore::class)->current();

            if (! $point || ! app(PointStore::class)->filtering()) {
                return $query;
            }

            return $query->where(fn ($outer) => $outer
                ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('m_warehouse_product_stock as any_stock')
                    ->whereColumn('any_stock.product_id', 'm_catalog_products.id'))
                ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('m_warehouse_product_stock as here')
                    ->whereColumn('here.product_id', 'm_catalog_products.id')
                    ->where('here.warehouse_id', $point->id)
                    ->where('here.qty', '>', 0)));
        });

        $this->townWindow();
    }

    /**
     * This module's part of "your town" (torgash.locations, PLAN §38): the
     * points in the town just chosen, the switch "stock at the chosen shop"
     * when the shop lets the customer decide, and the blue notice above a
     * listing that has become one shop's assortment. Soft both ways — the
     * hooks are simply never fired without the locations module.
     */
    protected function townWindow(): void
    {
        hook_listen('locations.dialog.sections', function (string $html, array $context) {
            $placeId = (int) ($context['place_id'] ?? 0);

            if ($placeId === 0) {
                return $html;
            }

            $store = app(PointStore::class);

            return $html.view('torgash.warehouses::partials.dialog-section', [
                'whPoints' => Warehouse::pickup()->where('place_id', $placeId)->orderBy('sort')->orderBy('id')->get(),
                'whChosen' => $store->current()?->place_id === $placeId ? $store->current()?->id : null,
                'whChoice' => setting('torgash.warehouses', 'point_availability', 'mark') === 'choice',
                'whOnly' => $store->onlyHere(),
            ])->render();
        });

        hook_listen('locations.dialog.saved', function (array $context) {
            $request = $context['request'];
            $store = app(PointStore::class);

            if (! $request->has('point_id')) {
                // The town has no points of ours: a shop chosen elsewhere is
                // not where this customer collects any more.
                if ($store->current() && $store->current()->place_id !== (int) $context['place_id']) {
                    $store->forget();
                }

                return;
            }

            $pointId = (int) $request->input('point_id');
            $pointId > 0 ? $store->choose($pointId) : $store->forget();
            $store->setOnlyHere($request->boolean('point_only'));
        });

        hook_listen('catalog.listing.notice', function (string $html) {
            $store = app(PointStore::class);

            return $store->current() && $store->filtering()
                ? $html.view('torgash.warehouses::partials.listing-notice', ['whPoint' => $store->current()])->render()
                : $html;
        });
    }
}
