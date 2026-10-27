<?php

namespace Torgash\Wishlist;

use App\Core\Storefronts\StorefrontRoutes;
use App\Core\Support\PerRequest;
use Illuminate\Auth\Events\Login;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Models\Product;

/**
 * Favourites (PLAN §35.3), wired in:
 * - the heart: in the photograph's corner of a catalogue card (catalog's
 *   `catalog.product.card_corner`, first, so a comparison toggle stacks under
 *   it) and under the product page's buy button (`cart.buy_box.actions`);
 * - the counter in the header (the theme's `storefront.header.icons`) and the
 *   item in the account menu (`core.account.nav`);
 * - signing in pours a guest's list into the account's (the Login event,
 *   which a new account fires too);
 * - guests' lists nobody came back to go nightly;
 * - step 2: sharing by a link, the price-drop and back-in-stock letters
 *   (letters()), the statistics screen (admin()).
 */
class WishlistServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Wishlists::class);
        PerRequest::forget($this->app, Wishlists::class);
    }

    public function boot(): void
    {
        // What it keeps about a person, for core's personal-data registry (PLAN §49).
        hook_listen('core.personal_data', fn (array $context) => WishlistPersonalData::register($context['registry']));

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        hook_listen('core.seo.private_routes', fn (array $names) => [...$names, 'wishlist.*']);

        // Its letters in core's registry (PLAN §41).
        hook_listen('core.mail.events', fn (array $events) => $events + \Torgash\Wishlist\Mail\WishlistLetters::events());

        // The customer card in the admin: this person's list.
        app(Admin\CustomerWishlist::class)->register();

        // A menu item can point at this page by name (torgash.menus, PLAN §39).
        hook_listen('menus.pages', fn (array $pages) => $pages + [
            'route:wishlist.index' => ['label' => 'Wishlist', 'route' => 'wishlist.index', 'icon' => 'heart'],
        ]);

        hook_listen('catalog.product.card_corner', function (string $html, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product || ! setting('torgash.wishlist', 'card_button')) {
                return $html;
            }

            return $html.view('torgash.wishlist::button', ['wishProduct' => $product, 'wishLabelled' => false])->render();
        }, 5);

        hook_listen('cart.buy_box.links', function (string $html, array $context) {
            $product = $context['product'] ?? null;

            return $product instanceof Product
                ? $html.view('torgash.wishlist::button', ['wishProduct' => $product, 'wishLabelled' => true])->render()
                : $html;
        });

        // Beside a line's bin on the cart page: "not now, but keep it".
        hook_listen('cart.line.actions', function (string $html, array $context) {
            $product = $context['product'] ?? null;

            return $product instanceof Product
                ? $html.view('torgash.wishlist::button', ['wishProduct' => $product, 'wishLabelled' => false, 'wishCompact' => true])->render()
                : $html;
        });

        hook_listen('storefront.header.icons', fn (string $html) => $html.view('torgash.wishlist::counter')->render());

        // The phone's tab bar (PLAN §38), between the catalogue and the profile.
        hook_listen('storefront.tabbar', fn (array $items) => [...$items, [
            'key' => 'wishlist', 'label' => __('Wishlist'), 'icon' => 'heart', 'url' => route('wishlist.index'),
            'badge' => app(Wishlists::class)->count() ?: null, 'order' => 30,
            'active' => request()->routeIs('*wishlist.*'),
        ]]);

        hook_listen('core.account.nav', fn (array $items) => [...$items, [
            'label' => __('Wishlist'),
            'group' => 'shopping',
            // One click from the header's account menu, not behind "All sections".
            'quick' => true,
            'url' => route('wishlist.index'),
            'icon' => 'heart',
            'active' => request()->routeIs('*wishlist.*'),
            'badge' => app(Wishlists::class)->count() ?: null,
        ]]);

        Event::listen(Login::class, fn (Login $event) => $event->user instanceof \App\Models\User
            ? app(Wishlists::class)->adopt($event->user)
            : null);

        hook_listen('core.schedule', function (array $context) {
            $context['schedule']->call(function () {
                $days = (int) setting('torgash.wishlist', 'keep_guest_days');

                if ($days > 0) {
                    Models\WishList::whereNull('user_id')->where('last_active_at', '<', now()->subDays($days))->delete();
                }
            })->daily()->name('wishlist:prune-guests');

            $context['schedule']->call(fn () => setting('torgash.wishlist', 'offer_notifications') ? Jobs\CheckPriceDrops::dispatch() : null)
                ->dailyAt('10:00')->name('wishlist:price-drops');
        });

        $this->letters();
        $this->admin();
    }

    /**
     * The two letters (PLAN §35.3). A price drop is LOOKED FOR — nightly, and
     * after an exchange rate moves, which moves every product priced in that
     * currency (after the catalogue has repriced, hence the late priority). A
     * return is HEARD: the catalogue announces every crossing back into
     * "buyable" and this collects the ones one action caused into one job.
     */
    protected function letters(): void
    {
        $this->app->singleton(Restocks::class);

        hook_listen('core.currency.rate_changed', function () {
            if (setting('torgash.wishlist', 'offer_notifications')) {
                Jobs\CheckPriceDrops::dispatch();
            }
        }, 50);

        hook_listen('catalog.product.restocked', function (array $context) {
            if (($id = (int) ($context['product']->id ?? 0)) && setting('torgash.wishlist', 'offer_notifications')) {
                app(Restocks::class)->add($id);
            }
        });

        $this->app->terminating(fn () => $this->app->make(Restocks::class)->flush());
        // After a job a WORKER ran (an import). Not a sync one: that runs
        // inside the request, which flushes at its end anyway — flushing after
        // each would split one save's returns into a letter apiece.
        Event::listen([JobProcessed::class, JobFailed::class], fn ($event) => $event->connectionName === 'sync'
            ? null
            : $this->app->make(Restocks::class)->flush());

        // Outside StorefrontRoutes: a signature covers one exact address, and
        // a locale prefix would be a second one.
        Route::middleware(['web', 'signed'])
            ->get('/wishlist/unsubscribe/{list}/{what}', [Http\WishlistController::class, 'unsubscribe'])
            ->whereIn('what', ['price', 'stock', 'all'])
            ->name('wishlist.unsubscribe');
    }

    /** What the shop learns from it: numbers and products, never people. */
    protected function admin(): void
    {
        Route::middleware(['web', 'auth', 'can:wishlist.stats'])
            ->prefix('admin/wishlist')
            ->name('admin.wishlist.')
            ->group(fn () => Route::get('/', [Http\Admin\StatsController::class, 'index'])->name('stats'));
    }
}
