<?php

namespace Torgash\CommerceMl;

use App\Core\Modules\ModuleLifecycle;
use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\CommerceMl\Import\Matching;

class CommerceMlServiceProvider extends ServiceProvider implements ModuleLifecycle
{
    /**
     * Put `/1c_exchange.php` in place — see LegacyEndpoint for why a module is
     * allowed to write into public/ at all.
     *
     * A failure is not fatal here: public/ may be read-only by design, and that
     * is no reason to refuse the whole module. The exchange screen shows the
     * state of the address and offers the button again.
     */
    public function install(): void
    {
        (new LegacyEndpoint)->install();
    }

    public function uninstall(): void
    {
        (new LegacyEndpoint)->remove();
    }

    public function register(): void
    {
        // A per-request memo, and a singleton rather than a static for the
        // reason the whole project gives: a static outlives the request under a
        // persistent worker and would answer the next exchange with the last
        // one's decisions.
        $this->app->singleton(Matching::class);
    }

    public function boot(): void
    {
        // A merge 1C has already taken — either order handed over and
        // confirmed after it — is 1C's fact too, and undoing it here would
        // leave the two systems telling different stories (PLAN §40.10/4).
        hook_listen('checkout.order.unmerge_refusals', function (array $refusals, array $context) {
            $taken = \Illuminate\Support\Facades\DB::table('m_commerceml_order_sync')
                ->whereIn('order_id', [$context['into']->id, $context['from']->id])
                ->where('confirmed_at', '>=', $context['merge']->created_at)
                ->exists();

            return $taken ? [...$refusals, __('1C has already taken these orders as merged.')] : $refusals;
        });

        // The one executable file this module deliberately puts in public/, so
        // a watchman scanning for exactly that does not report our own doing.
        // Only while it is OURS: a script left there by the engine this shop
        // came from is somebody else's and stays on the list.
        // Exchange sessions and the per-order sync state are this module's own
        // logs, written by 1C's calls — not somebody's act to audit.
        hook_listen('guard.audit.ignored_tables', fn (array $tables) => [...$tables, 'm_commerceml_exchanges', 'm_commerceml_order_sync']);

        hook_listen('guard.files.expected', function (array $paths) {
            $endpoint = new LegacyEndpoint;

            return $endpoint->isOurs() ? [...$paths, $endpoint->path()] : $paths;
        });

        // NOT the `web` group. 1C has no session, no CSRF token and no locale
        // prefix, and every answer it reads is a bare word on the first line —
        // a 419 page or a redirect to /ru/ is an exchange that fails with no
        // explanation. Same reasoning as the payments callback route.
        Route::group([], dirname(__DIR__).'/routes.php');

        Route::middleware(['web', 'auth', 'can:commerceml.manage'])
            ->prefix('admin/commerceml')
            ->name('admin.commerceml.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        $this->deliveryService();
    }

    /**
     * The 1C identifier of the service a delivery method is billed to, asked for
     * on the delivery method itself.
     *
     * It has to be per method, because a shop that delivers by its own courier
     * AND by a parcel carrier bills one of them in 1C and the other nowhere at
     * all. It has to be on the shipping screen, because that is where a person
     * setting up "courier delivery" is standing. And it may not be a column on
     * the shipping method's table, because that table is another module's — so
     * it is stored here, as this module's own setting, keyed by the method.
     *
     * The same pair of hooks as a gateway on a payment method, and added to
     * shipping along with this: a filter that prints the field, an action that
     * lets whoever printed it store what came back.
     */
    protected function deliveryService(): void
    {
        hook_listen('shipping.method.extra', function (string $value, array $context) {
            return $value.view('torgash.commerceml::partials.shipping-service', [
                'guid' => $this->serviceGuids()[(string) ($context['method']->id ?? '')] ?? '',
            ])->render();
        });

        hook_listen('shipping.method.saved', function (array $context) {
            $method = $context['method'];
            $guid = trim((string) $context['request']->input('commerceml_service_guid', ''));
            $guids = $this->serviceGuids();

            // Cleared means gone, not blank: an empty string left in the map
            // would read as "this method has an identifier" to everything that
            // asks, and the delivery line would go out pointing at nothing.
            if ($guid === '') {
                unset($guids[(string) $method->id]);
            } else {
                $guids[(string) $method->id] = $guid;
            }

            app(SettingsManager::class)
                ->set('torgash.commerceml', 'sale_service_guids', $guids);
        });
    }

    /** @return array<string, string> shipping method id => 1C nomenclature id */
    protected function serviceGuids(): array
    {
        return (array) setting('torgash.commerceml', 'sale_service_guids', []);
    }
}
