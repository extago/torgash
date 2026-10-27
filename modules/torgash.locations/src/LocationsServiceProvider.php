<?php

namespace Torgash\Locations;

use App\Core\Content\Placeholders;
use App\Core\Modules\ModuleLifecycle;
use App\Core\Storefronts\StorefrontRoutes;
use App\Core\Support\PerRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Locations\Jobs\ImportPack;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;

class LocationsServiceProvider extends ServiceProvider implements ModuleLifecycle
{
    /**
     * A shop that knows its country starts with its map: the world's list of
     * countries and — queued, it is tens of thousands of rows — the pack of
     * the shop's own country when one is in the packs folder (Ukraine and
     * Russia ship with the engine). Before this a new shop had no country,
     * no region and no town at all, and nothing said where they come from
     * (the first clean install, 2026-10-27). A shop with no country set (and
     * the test schema) gets nothing: the Import screen is the way in.
     */
    public function install(): void
    {
        $country = strtoupper(trim((string) setting(\App\Core\Settings\CoreSettings::ID, 'default_country')));

        if ($country === '') {
            return;
        }

        $importer = app(PackImporter::class);
        $root = base_path((string) setting('torgash.locations', 'packs_path'));

        if (is_file($root.'/countries.csv')) {
            $fresh = ! Country::query()->exists();
            $importer->importCountries($root.'/countries.csv');

            // A shop sells in its own country until it says otherwise: with
            // all 252 switched on, a Ukrainian shop's checkout opened on
            // "Choose a country". A list the shop already curated is its own.
            if ($fresh) {
                Country::query()->where('code', '!=', $country)->update(['enabled' => false]);
            }
        }

        $pack = PackImporter::available($root)[$country] ?? null;

        if ($pack && ! Place::query()->where('country', $country)->exists()) {
            ImportPack::start($pack['states'], $pack['cities'], $country);
        }
    }

    /** Its tables go with its migrations; nothing was written anywhere else. */
    public function uninstall(): void {}

    public function register(): void
    {
        $this->app->singleton(PackImporter::class);
        // One per request: a city page mentions its town in four places, and
        // everything that stores a place stores only its id. See Places.
        $this->app->scoped(Places::class);
        PerRequest::forget($this->app, Places::class);
    }

    public function boot(): void
    {
        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        // "Only to visitors from…" — display rules about the chosen town.
        VisitorRules::register();

        // "Your town" in the header's corner and the window it opens (PLAN
        // §38). The town is read through checkout's `storefront.visitor_place`
        // — resolved at render time, inside the listener, never at boot.
        hook_listen('storefront.header.start', function (string $html, array $context) {
            $placeId = (int) hook_filter('storefront.visitor_place', null);

            return $html.view('torgash.locations::header-button', [
                'locPlace' => $placeId ? Models\Place::find($placeId) : null,
                'locHeader' => $context['header'] ?? 'dark',
            ])->render();
        });

        hook_listen('core.footer', fn (string $html) => $html.view('torgash.locations::dialog-shell')->render());

        // "Is your town …?" (setting `town_prompt`): once a visit, only to a
        // visitor who has not chosen a town. Marked as asked when it is
        // printed, so the next page does not ask again whatever they did.
        hook_listen('core.footer', function (string $html) {
            $guess = app(TownGuess::class);
            $mode = $guess->mode();

            if ($mode === 'off' || session('locations.town_prompted') || hook_filter('storefront.visitor_place', null)) {
                return $html;
            }

            $place = $guess->guess(request()->ip());

            // Without a town to name only the browser may still find one.
            if (! $place && $mode !== 'browser') {
                return $html;
            }

            session()->put('locations.town_prompted', true);

            return $html.view('torgash.locations::town-prompt', ['townMode' => $mode, 'townGuess' => $place])->render();
        });

        // The country of the visitor's town — what a phone number typed
        // without its + is read in (core's Phones). A place's `country` is
        // its ISO code.
        hook_listen('core.visitor.country', function ($country) {
            if ($country) {
                return $country;
            }

            $placeId = (int) hook_filter('storefront.visitor_place', null);

            return $placeId ? Models\Place::whereKey($placeId)->value('country') : null;
        });

        // The same town as a BLOCK (the phone menu's row, PLAN §38) — only
        // where the blocks module is there to place it.
        if (class_exists(\Torgash\Blocks\BlockTypes::class)) {
            \Torgash\Blocks\BlockTypes::register('town', [
                'description' => "The visitor's town and the window to change it.",
                'fits' => ['inline', 'drawer', 'narrow'],
                'label' => 'Your town',
                'icon' => 'map-pin',
                'view' => 'torgash.locations::block-town',
                'schema' => [],
            ]);
        }

        // Checkout knows a place only by its id — it must keep working with
        // this module uninstalled — so the registry answers what country and
        // region that id is in.
        hook_listen('checkout.location.for_place', function (array $value, array $context) {
            $place = Models\Place::with('state')->find($context['place_id'] ?? 0);

            if ($place === null) {
                return $value;
            }

            return [
                'country' => $place->country,
                'state' => $place->state?->code,
                'postal' => $place->postal_from,
            ];
        });

        // Checkout has no country list of its own — it must run without this
        // module — so it asks, and gets nothing back when the registry is not
        // installed, which is exactly the shape "one unnamed country" had
        // before countries were selectable at all.
        hook_listen('checkout.location.countries', function (array $value) {
            return Models\Country::where('enabled', true)->get()
                ->map(fn (Models\Country $country) => [
                    'code' => $country->code,
                    'name' => $country->t('name') ?: $country->code,
                ])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();
        });

        // The region list for the zone editor. A region code repeats between
        // countries ("12" is one in Ukraine and another in Russia), so each
        // item carries its country and the composite value the rule stores
        // ("UA:12") ready-made — the editor never has to assemble it.
        hook_listen('checkout.location.regions', function (array $value) {
            $countries = Models\Country::where('enabled', true)->get()
                ->mapWithKeys(fn (Models\Country $c) => [$c->code => $c->t('name') ?: $c->code]);

            return Models\State::where('enabled', true)->get()
                ->map(fn (Models\State $state) => [
                    'value' => $state->country.':'.$state->code,
                    'country' => $state->country,
                    'country_name' => $countries[$state->country] ?? $state->country,
                    'name' => $state->t('name') ?: $state->code,
                ])
                ->sortBy(fn (array $r) => $r['country_name'].' '.$r['name'], SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();
        });

        // "Name these place ids." Anything that stores a place keeps only its
        // id — a zone rule, a warehouse — and every admin screen showing one
        // needs the name. Answered in one query for the whole page rather than
        // a lookup per row.
        //
        // Named after this module rather than after whoever asks, unlike the
        // hooks above: those are seams checkout owns and this module happens to
        // fill, while this one is a question only a place registry can answer.
        hook_listen('locations.place.names', function (array $value, array $context) {
            $ids = array_filter(array_map('intval', $context['ids'] ?? []));

            if ($ids === []) {
                return $value;
            }

            return app(Places::class)->prime($ids)->only($ids)
                ->mapWithKeys(fn (Models\Place $place) => [$place->id => trim(
                    ($place->t('name') ?: (string) $place->id)
                    .($place->t('district') || $place->state ? ' — '.collect([
                        $place->t('district'), $place->state?->t('name'),
                    ])->filter()->unique()->implode(' · ') : '')
                )])
                ->all() + $value;
        });

        // The same towns in a CASE a sentence needs — "Магазины в Москве" is
        // case 6 (Place::form()), in the page's language. For a block that
        // groups things by town and must not name this module's classes.
        hook_listen('locations.place.forms', function (array $value, array $context) {
            $ids = array_filter(array_map('intval', $context['ids'] ?? []));
            $case = max(1, (int) ($context['case'] ?? 1));

            if ($ids === []) {
                return $value;
            }

            return app(Places::class)->prime($ids)->only($ids)
                ->mapWithKeys(fn (Models\Place $place) => [$place->id => $place->form($case) ?: (string) $place->t('name')])
                ->all() + $value;
        });

        // "This page is about these towns" — answered in one query, so a
        // category listing twenty city pages costs one lookup rather than
        // twenty. The same shape as `catalog.products.listed`: whoever holds
        // the page announces its set, and whoever owns the rows answers.
        hook_listen('locations.places.listed', function (array $context) {
            app(Places::class)->prime($context['ids'] ?? []);
        });

        // What a template may say about the town a page is about. Registered
        // here for the reason the catalogue's product tokens are registered
        // there: the tokens belong to whoever owns the data, and the declined
        // forms of a town's name are this module's.
        (new Content\LocationPlaceholders)->register(app(Placeholders::class));

        Route::middleware(['web', 'auth', 'can:locations.manage'])
            ->prefix('admin/locations')
            ->name('admin.locations.')
            ->group(dirname(__DIR__).'/routes-admin.php');
    }
}
