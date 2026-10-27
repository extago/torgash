<?php

namespace Torgash\Menus;

use App\Core\Support\PerRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Blocks\BlockTypes;

class MenusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per request: rows of the shop's own (menus, pages, counts) — a
        // static or a boot-time capture would serve the next visitor stale ones.
        $this->app->singleton(ShopPages::class);
        $this->app->singleton(Shortcuts::class);
        $this->app->singleton(ItemKinds::class);
        $this->app->singleton(Looks::class);
        $this->app->singleton(CategoryCounts::class);
        $this->app->singleton(MenuBuilder::class);
        $this->app->singleton(Placements::class);

        PerRequest::forget($this->app, ShopPages::class, Shortcuts::class, ItemKinds::class, Looks::class, CategoryCounts::class, MenuBuilder::class, Placements::class);
    }

    public function boot(): void
    {
        // Its part of the demo shop (PLAN §55.3), after its pages.
        hook_listen('core.demo.parts', fn (array $parts) => $parts + [
            'menus' => ['label' => 'Menus', 'after' => ['pages'], 'install' => fn (\App\Core\Demo\DemoPack $pack) => app(Demo\MenusDemo::class)->install($pack)],
        ]);

        Route::middleware(['web', 'auth', 'can:menus.manage'])
            ->prefix('admin/menus')
            ->name('admin.menus.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        $this->registerShopPages();
        $this->registerBlockType();

        // A theme setting of type "menu" (the catalogue button's menu, the
        // strip under the header): core asks, this module answers with the
        // shop's menus, and core never learns what a menu is.
        hook_listen('core.theme.options.menus', fn (array $options) => $options + app(MenuBuilder::class)->options());
    }

    /**
     * The pages core and the catalogue own. Every other module registers its
     * own (promotions its sale, the wishlist itself…) — this module names
     * only what it already depends on.
     */
    protected function registerShopPages(): void
    {
        hook_listen('menus.pages', fn (array $pages) => [
            'route:catalog.home' => ['label' => 'Home page', 'route' => 'catalog.home', 'icon' => 'house'],
            'route:catalog.index' => ['label' => 'All products', 'route' => 'catalog.index', 'icon' => 'layout-grid'],
            'route:catalog.brands.index' => ['label' => 'Brands', 'route' => 'catalog.brands.index', 'icon' => 'tag'],
            'route:account.dashboard' => ['label' => 'My account', 'route' => 'account.dashboard', 'icon' => 'user-round'],
            'route:account.orders' => ['label' => 'My orders', 'route' => 'account.orders', 'icon' => 'package'],
        ] + $pages);
    }

    /**
     * The block that puts a menu somewhere (PLAN §39.2): which menu, drawn how,
     * how deep. Switching it off, or showing it only on one storefront, in one
     * language or to one customer group, is what every block already does.
     */
    protected function registerBlockType(): void
    {
        if (! class_exists(BlockTypes::class)) {
            return;
        }

        BlockTypes::register('menu', [
            'description' => 'One of the menus built under Menus — as a line, a list, columns or a drawer.',
            'fits' => ['wide', 'narrow', 'inline', 'drawer'],
            'label' => 'Menu',
            'icon' => 'menu',
            'view' => 'torgash.menus::blocks.menu',
            'schema' => [
                ['key' => 'menu', 'type' => 'select', 'label' => 'Menu',
                    'help' => 'Menus are edited under Appearance → Menus.',
                    'options' => fn () => collect(app(MenuBuilder::class)->options())
                        ->map(fn (string $name, string $code) => ['value' => $code, 'label' => ['en' => $name.' ('.$code.')']])
                        ->values()->all()],
                ['key' => 'look', 'type' => 'select', 'default' => 'inline', 'label' => 'Look',
                    'options' => fn () => app(Looks::class)->blockOptions()],
                ['key' => 'heading', 'type' => 'text', 'translatable' => true, 'label' => 'Heading',
                    'help' => 'For "one button" this is the word on the button; for a list, the line above it. Optional otherwise.'],
                ['key' => 'icon', 'type' => 'text', 'label' => 'Icon beside the heading',
                    'help' => 'A lucide.dev name, e.g. info. Optional.'],
                ['key' => 'depth', 'type' => 'number', 'default' => 2, 'label' => 'Levels to draw',
                    'help' => '1 — only the top items. A look draws no more levels than it can hold.'],
                ['key' => 'icons', 'type' => 'checkbox', 'default' => true, 'label' => 'Show the items\' icons'],
                ['key' => 'badges', 'type' => 'checkbox', 'default' => true, 'label' => 'Show the items\' badges'],
            ],
        ]);
    }
}
