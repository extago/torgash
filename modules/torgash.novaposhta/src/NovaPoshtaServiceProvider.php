<?php

namespace Torgash\NovaPoshta;

use App\Core\Storefronts\StorefrontRoutes;
use App\Core\Support\PerRequest;
use DateInterval;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\NovaPoshta\Api\Client;
use Torgash\NovaPoshta\Jobs\SyncDirectory;
use Torgash\NovaPoshta\Jobs\SyncWarehouses;
use Torgash\NovaPoshta\Models\PlaceMap;
use Torgash\NovaPoshta\Models\Sync;
use Torgash\NovaPoshta\Shipping\NearestTowns;
use Torgash\NovaPoshta\Shipping\Notice;
use Torgash\NovaPoshta\Shipping\NovaPoshtaProvider;
use Torgash\NovaPoshta\Sync\WarehouseSync;

class NovaPoshtaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Rebound per resolve rather than shared: a job may want a specific
        // key, and the setting can change between requests.
        $this->app->bind(Client::class, fn () => new Client);

        // "This quote had nothing to offer" is about ONE quote, so it is reset
        // with the request — under a persistent worker a remembered notice
        // would tell the next visitor their town has no branches.
        $this->app->singleton(Notice::class);
        $this->app->singleton(NearestTowns::class);

        PerRequest::forget($this->app, Notice::class);
    }

    public function boot(): void
    {
        // The sync's bookkeeping is on no guest's page (PLAN §61).
        page_cache()->unseen('m_np_sync');

        // Views and JSON translations are registered for every enabled module
        // by CoreServiceProvider — nothing to do here.

        // Directory sync state is this module's own log, not an act to audit.
        hook_listen('guard.audit.ignored_tables', fn (array $tables) => [...$tables, 'm_np_sync']);

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        hook_listen(
            'shipping.providers',
            fn (array $providers) => $providers + ['nova_poshta' => NovaPoshtaProvider::class],
        );

        Route::middleware(['web', 'auth', 'can:novaposhta.manage'])
            ->prefix('admin/nova-poshta')
            ->name('admin.nova-poshta.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        hook_listen('core.schedule', $this->schedule(...));

        $this->warmOnTownChosen();
        $this->explainEmptyTowns();
    }

    /**
     * "There is no branch in your village — the nearest one is 18 km away."
     *
     * The carrier is right to be absent from a destination it cannot serve;
     * being absent with no word about why is what makes a shop look broken.
     * The provider records that it was asked, had nothing, and WHY (`Notice`),
     * and this prints the sentence that reason deserves — for a village with
     * nothing, the nearest towns that do have a branch, with the distance and
     * what each of them has, as buttons that move the order there.
     *
     * The nearest few are warmed while the customer reads the list, so the
     * kinds — a locker, a branch, a cargo department — are there the second
     * time they look and the branch picker is instant when they choose one.
     */
    protected function explainEmptyTowns(): void
    {
        hook_listen('shipping.quote.notices', function (string $html) {
            $notice = app(Notice::class);
            $placeId = $notice->place();

            if (! $placeId) {
                return $html;
            }

            // Somewhere else to collect from is an answer to exactly one of
            // the three reasons. A parcel too heavy for this town is too heavy
            // for the next one, and a town whose branches we could not fetch
            // probably has plenty — directions from either would be confident
            // and wrong.
            $towns = $notice->reason() === Notice::NONE
                ? app(NearestTowns::class)->around($placeId)
                : [];

            foreach (array_slice($towns, 0, NearestTowns::WARM) as $town) {
                $ref = PlaceMap::whereKey($town['place_id'])->value('settlement_ref');

                if ($ref) {
                    app(WarehouseSync::class)->warm((string) $ref);
                }
            }

            return $html.view('torgash.novaposhta::checkout.nearest', [
                'reason' => $notice->reason(),
                'params' => $notice->params(),
                'towns' => $towns,
            ])->render();
        });
    }

    /**
     * A town the customer has just named gets its branches fetched in the
     * background, before anybody is waiting for them.
     *
     * The announcement is the shop's own (`storefront.place_chosen`): a geo
     * page, the pickup-point picker, and — since this was written — the town
     * box on the cart and checkout pages, which is where a town is named in
     * almost every order. What it buys is the case that made the owner ask: a
     * carrier that refuses the very first request for an unknown town used to
     * leave that customer with no Nova Poshta at all, because the fetch
     * happened in the request that needed the list. Now the fetch has usually
     * already happened while they were filling in their name.
     *
     * A setting rather than always: a shop that has the whole country in its
     * table has nothing to warm, and one with no queue worker gains nothing
     * either — it costs one query per named town to find that out.
     */
    protected function warmOnTownChosen(): void
    {
        hook_listen('storefront.place_chosen', function (array $context) {
            if (! setting('torgash.novaposhta', 'prefetch_on_town', true)) {
                return;
            }

            if ((string) setting('torgash.novaposhta', 'api_key', '') === '') {
                return;
            }

            $placeId = (int) ($context['place_id'] ?? 0);
            $ref = $placeId ? PlaceMap::whereKey($placeId)->value('settlement_ref') : null;

            if ($ref) {
                app(WarehouseSync::class)->warm((string) $ref);
            }
        });
    }

    /**
     * The carrier's data has to be kept fresh, and until the project had a
     * scheduler that meant pressing buttons on the ops screen. Now it does.
     *
     * @param  array{schedule: Schedule}  $context
     */
    protected function schedule(array $context): void
    {
        // No key means no account to sync against — and SyncDirectory would
        // only error. The ops screen still offers the manual buttons.
        if ((string) setting('torgash.novaposhta', 'api_key', '') === '') {
            return;
        }

        $schedule = $context['schedule'];

        // The branch top-up is built to be re-run: one pass works inside a time
        // budget and stops, the next continues from each town's own freshness
        // marker (SyncWarehouses docblock). A nightly pass is how the ~9 000
        // towns still without branches get covered without a nightly button.
        $schedule->call(fn () => SyncWarehouses::startIfIdle())
            ->dailyAt('02:30')
            ->name('novaposhta:sync-warehouses');

        // The settlement directory changes slowly — a weekly refresh, the same
        // cadence the carrier's own plug-ins use, and skipped while it
        // is still inside its TTL so this does not re-pull 27 000 rows every
        // week for nothing.
        $schedule->call(function () {
            if (Sync::stale('settlements', new DateInterval('P7D'))) {
                SyncDirectory::startIfIdle();
            }
        })->weekly()->name('novaposhta:sync-directory');
    }
}
