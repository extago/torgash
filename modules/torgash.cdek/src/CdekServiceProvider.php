<?php

namespace Torgash\Cdek;

use App\Core\Storefronts\StorefrontRoutes;
use App\Core\Support\PerRequest;
use DateInterval;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Cdek\Api\Client;
use Torgash\Cdek\Http\Admin\WaybillsController;
use Torgash\Cdek\Jobs\RefreshWaybills;
use Torgash\Cdek\Jobs\SyncCities;
use Torgash\Cdek\Jobs\SyncPoints;
use Torgash\Cdek\Models\PlaceMap;
use Torgash\Cdek\Models\Sync;
use Torgash\Cdek\Models\Waybill;
use Torgash\Cdek\Shipping\CdekProvider;
use Torgash\Cdek\Shipping\NearestTowns;
use Torgash\Cdek\Shipping\Notice;
use Torgash\Cdek\Sync\PointSync;
use Torgash\Cdek\Waybills\Waybills;

class CdekServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Rebound per resolve rather than shared: the keys and the test toggle
        // can change between requests.
        $this->app->bind(Client::class, fn () => new Client);

        // "This quote had nothing to offer" is about ONE quote, so it is reset
        // with the request.
        $this->app->singleton(Notice::class);
        $this->app->singleton(NearestTowns::class);

        PerRequest::forget($this->app, Notice::class);
    }

    public function boot(): void
    {
        // Waybills and the sync's bookkeeping are on no guest's page (PLAN §61).
        page_cache()->unseen(['m_cdek_waybills', 'm_cdek_sync']);

        // Sync markers are this module's own log, not an act to audit.
        hook_listen('guard.audit.ignored_tables', fn (array $tables) => [...$tables, 'm_cdek_sync']);

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        hook_listen('shipping.providers', fn (array $providers) => $providers + ['cdek' => CdekProvider::class]);

        Route::middleware(['web', 'auth', 'can:cdek.manage'])
            ->prefix('admin/cdek')
            ->name('admin.cdek.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        Route::middleware(['web', 'auth', 'can:cdek.waybills'])
            ->prefix('admin/cdek')
            ->name('admin.cdek.')
            ->group(dirname(__DIR__).'/routes-admin-waybills.php');

        hook_listen('core.schedule', $this->schedule(...));

        $this->warmOnTownChosen();
        $this->explainEmptyTowns();
        $this->waybills();
    }

    /**
     * The order's side of it: a CDEK card on the order screen of every order
     * sent with CDEK, and the tracking number on the customer's own order
     * page once there is one. Checkout names no carrier — both go through its
     * seams (`checkout.order.side_panels`, `checkout.confirmation.delivery`).
     */
    protected function waybills(): void
    {
        hook_listen('checkout.order.side_panels', function (array $panels, array $context) {
            $order = $context['order'];

            if (! Waybills::applies($order) || ! auth()->user()?->can('cdek.waybills')) {
                return $panels;
            }

            return [...$panels, [
                'key' => 'cdek.waybill',
                'position' => 10,
                'html' => view('torgash.cdek::admin.waybill-panel', WaybillsController::panelData($order) + [
                    'waybillError' => null,
                    'waybillOld' => [],
                ])->render(),
            ]];
        });

        hook_listen('checkout.confirmation.delivery', function (string $html, array $context) {
            $waybill = Waybill::where('order_id', $context['order']->id)
                ->where('state', Waybill::ACTIVE)
                ->whereNotNull('cdek_number')
                ->latest('id')
                ->first();

            return $waybill ? $html.view('torgash.cdek::checkout.tracking', ['waybill' => $waybill])->render() : $html;
        });
    }

    /**
     * "There is no CDEK point in your village — the nearest ones are here."
     * The provider records that it was asked, had nothing, and why (Notice);
     * this prints the sentence that reason deserves.
     */
    protected function explainEmptyTowns(): void
    {
        hook_listen('shipping.quote.notices', function (string $html) {
            $notice = app(Notice::class);
            $placeId = $notice->place();

            if (! $placeId) {
                return $html;
            }

            // Somewhere else to collect from answers exactly one of the three
            // reasons: a parcel too heavy here is too heavy next door, and a
            // town we could not ask about probably has plenty.
            $towns = $notice->reason() === Notice::NONE
                ? app(NearestTowns::class)->around($placeId)
                : [];

            return $html.view('torgash.cdek::checkout.nearest', [
                'reason' => $notice->reason(),
                'params' => $notice->params(),
                'towns' => $towns,
            ])->render();
        });
    }

    /**
     * A town the customer has just named gets its points fetched in the
     * background, before anybody is waiting for them — the shop's own
     * announcement, `storefront.place_chosen`.
     */
    protected function warmOnTownChosen(): void
    {
        hook_listen('storefront.place_chosen', function (array $context) {
            if (! setting('torgash.cdek', 'prefetch_on_town', true) || ! Client::configured()) {
                return;
            }

            $placeId = (int) ($context['place_id'] ?? 0);
            $code = $placeId ? PlaceMap::whereKey($placeId)->value('city_code') : null;

            if ($code) {
                app(PointSync::class)->warm((int) $code);
            }
        });
    }

    /** @param  array{schedule: Schedule}  $context */
    protected function schedule(array $context): void
    {
        // No keys, no account to sync against. The data screen still offers
        // the manual buttons.
        if (! Client::configured()) {
            return;
        }

        $schedule = $context['schedule'];

        // CDEK answers for the whole country in one paged listing, so the
        // nightly pass is complete rather than continued night after night.
        $schedule->call(fn () => SyncPoints::startIfIdle())
            ->dailyAt('03:10')
            ->name('cdek:sync-points');

        $schedule->call(function () {
            $days = max(1, (int) setting('torgash.cdek', 'cities_ttl_days', 7));

            if (Sync::stale('cities', new DateInterval('P'.$days.'D'))) {
                SyncCities::startIfIdle();
            }
        })->weekly()->name('cdek:sync-cities');

        // Where every parcel is. CDEK can also call a webhook, but that needs
        // the shop reachable from outside and registered with CDEK; asking
        // every half hour needs neither, and a status half an hour old is what
        // the carrier's own plug-ins show too.
        $schedule->call(fn () => RefreshWaybills::dispatch())
            ->everyThirtyMinutes()
            ->name('cdek:waybill-statuses');
    }
}
