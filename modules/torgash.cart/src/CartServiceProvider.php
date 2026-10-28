<?php

namespace Torgash\Cart;

use App\Core\Storefronts\StorefrontRoutes;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class CartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CartManager::class);
        $this->app->singleton(ListBuy::class);
        \App\Core\Support\PerRequest::forget($this->app, ListBuy::class);
    }

    public function boot(): void
    {
        // An account's saved basket, for core's personal-data registry (PLAN §49).
        hook_listen('core.personal_data', fn (array $context) => $context['registry']->register('carts', [
            'label' => 'Saved basket',
            'erase' => fn (\App\Core\Privacy\Subject $s) => $s->user ? Models\Cart::where('user_id', $s->user->id)->delete() : 0,
        ]));

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        hook_listen('core.theme.preview_pages', fn (array $pages) => [...$pages,
            ['label' => 'Basket', 'icon' => 'shopping-cart', 'path' => route('cart.index', [], false), 'position' => 40],
        ]);

        // A menu item can point at this page by name (torgash.menus, PLAN §39).
        hook_listen('menus.pages', fn (array $pages) => $pages + [
            'route:cart.index' => ['label' => 'Basket', 'route' => 'cart.index', 'icon' => 'shopping-cart'],
        ]);

        // Catalog owns the product page but knows nothing about carts; it
        // renders whatever this hook returns instead of its own disabled button.
        hook_listen('catalog.product.buy_box', function ($value, array $context) {
            return view('torgash.cart::partials.buy-box', ['product' => $context['product']])->render();
        });

        $this->buyingFromLists();

        // A basket is one visitor's: its token is read in personal parts only
        // (the badge, the ticks), and nothing about it is on a guest's page.
        page_cache()
            ->personal(session: ['cart_token', 'cart', 'cart_coupon'])
            ->unseen('m_cart_carts');

        hook_listen('core.schedule', $this->schedule(...));

        // A cart is one visitor's, changes on every click and means nothing to
        // anyone else — an index entry for it is a page a search engine will
        // fetch for ever and never usefully show.
        hook_listen('core.seo.private_routes', fn (array $names) => [...$names, 'cart.*']);
    }

    /**
     * Buying from a list: the card's button (catalog draws whatever
     * `catalog.product.card_actions` answers and knows nothing of carts), a
     * page's set primed at once for it, and on every storefront page the one
     * dialog the choice window and the "added" window are drawn into, the
     * corner the notices appear in, and the script that opens and closes them.
     */
    protected function buyingFromLists(): void
    {
        hook_listen('catalog.products.listed', function (array $context) {
            app(ListBuy::class)->prime($context['products'] ?? []);
        });

        // Quick view (setting `quick_view`): an eye in the card's corner, after
        // the wishlist's heart and the comparison — it opens the shared dialog;
        // without script it is a link to the page.
        hook_listen('catalog.product.card_corner', function (string $html, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof \Torgash\Catalog\Models\Product || ! setting('torgash.cart', 'quick_view', true)) {
                return $html;
            }

            return $html.view('torgash.cart::partials.quick-button', ['quickProduct' => $product])->render();
        }, 30);

        hook_listen('catalog.product.card_actions', function (string $html, array $context) {
            if (! setting('torgash.cart', 'card_buttons') || ! ($context['product'] ?? null)) {
                return $html;
            }

            // A caller with room for it (a list's ROW, not a card) asks for the
            // quantity to stand open: `quantity => open`. Hidden until hover is
            // a card's economy, and in a row it only made the button jump.
            $open = ($context['quantity'] ?? null) === 'open';

            return $html.view('torgash.cart::partials.list-buy', [
                'listBuyProduct' => $context['product'],
                'listBuyQuantity' => $open || (bool) setting('torgash.cart', 'card_quantity'),
                'listBuyQuantityOpen' => $open,
                'listBuyAfter' => null,
                // `variation => chosen`: a row that IS one variation (the
                // variants table) adds it, rather than asking which.
                'listBuyVariationChosen' => ($context['variation'] ?? null) === 'chosen',
            ])->render();
        });

        hook_listen('core.footer', fn (string $html) => $html.view('torgash.cart::partials.dialog')->render()
            .personal('torgash.cart::partials.in-cart-marks', reusable: true));

        // The basket's tab on a phone (PLAN §38): last, with its count — the
        // same count the header badge carries, refreshed with it after an add.
        hook_listen('storefront.tabbar', fn (array $items) => [...$items, [
            'key' => 'cart', 'label' => __('Basket'), 'icon' => 'shopping-cart', 'url' => route('cart.index'),
            'badge' => app(CartManager::class)->count() ?: null, 'order' => 90,
            // The way to an order, not an order already placed: its page and
            // invoice belong to the account the customer reads them in.
            'active' => request()->routeIs('*cart.*', '*checkout.*')
                && ! request()->routeIs('*checkout.confirmation', '*checkout.invoice', '*checkout.enquiry'),
        ]]);
    }

    /**
     * Carts nobody came back to.
     *
     * They cost nothing to keep and they are worth keeping for a while: an
     * abandoned cart is the shop's best guess at what somebody nearly bought,
     * and mailing about one is a feature this table now makes possible. But
     * "for a while" is not "for ever" — every crawler that adds to a cart
     * leaves one behind.
     *
     * @param  array{schedule: Schedule}  $context
     */
    protected function schedule(array $context): void
    {
        $context['schedule']->call(function () {
            $days = (int) setting('torgash.cart', 'keep_carts_days', 60);

            if ($days > 0) {
                Models\Cart::where('last_active_at', '<', now()->subDays($days))->delete();
            }
        })->daily()->name('cart:prune-abandoned');
    }
}
