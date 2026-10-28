<?php

namespace Torgash\Compare;

use App\Core\Storefronts\StorefrontRoutes;
use App\Core\Support\PerRequest;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Models\Product;

/**
 * Comparison (PLAN §35.4), wired in:
 * - the button: in the photograph's corner of a catalogue card, under the
 *   heart (catalog's `catalog.product.card_corner`, after the wishlist's) and
 *   under the product page's buy button, with words (`cart.buy_box.actions`);
 * - the counter in the header (the theme's `storefront.header.icons`);
 * - the page is nobody's search result (`core.seo.private_routes`).
 */
class CompareServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Comparison::class);
        PerRequest::forget($this->app, Comparison::class);
    }

    public function boot(): void
    {
        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        hook_listen('core.seo.private_routes', fn (array $names) => [...$names, 'compare.*']);

        // A menu item can point at this page by name (torgash.menus, PLAN §39).
        hook_listen('menus.pages', fn (array $pages) => $pages + [
            'route:compare.index' => ['label' => 'Comparison', 'route' => 'compare.index', 'icon' => 'square-kanban'],
        ]);

        hook_listen('catalog.product.card_corner', function (string $html, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product || ! setting('torgash.compare', 'card_button')) {
                return $html;
            }

            // The visitor's own on a page kept ready for guests (PLAN §61) —
            // for a guest comparing nothing, as it was drawn.
            return $html.personal('torgash.compare::button', ['compareProduct' => $product, 'compareLabelled' => false], reusable: true);
        }, 20);

        hook_listen('cart.buy_box.links', function (string $html, array $context) {
            $product = $context['product'] ?? null;

            return $product instanceof Product
                ? $html.personal('torgash.compare::button', ['compareProduct' => $product, 'compareLabelled' => true], reusable: true)
                : $html;
        }, 20);

        // A shortcut a menu can offer (the phone's bottom bar, the drawer) —
        // 'auto' => false keeps it out of the AUTOMATIC bar, whose five
        // places are already the basket's and the wishlist's.
        hook_listen('storefront.tabbar', fn (array $items) => [...$items, [
            'key' => 'compare', 'label' => __('Comparison'), 'icon' => 'square-kanban', 'url' => route('compare.index'),
            'badge' => app(Comparison::class)->count() ?: null, 'order' => 40, 'auto' => false,
            'active' => request()->routeIs('*compare.*'),
        ]]);

        hook_listen('storefront.header.icons', fn (string $html) => $html.personal('torgash.compare::counter', reusable: true), 20);

        page_cache()->personal(session: [Comparison::SESSION]);
    }
}
