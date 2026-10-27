<?php

namespace Torgash\Locations\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;

/**
 * "Your town" (PLAN §38): the window in the header's corner.
 *
 * This module owns the TOWN and nothing else. Where the visitor is stored is
 * checkout's business (`storefront.visitor_place` to read it,
 * `storefront.place_chosen` to say it — with `explicit`, because here the
 * customer is naming it themselves); what the town means for the shop's own
 * points is the warehouses module's, which adds its part of the window through
 * `locations.dialog.sections` and hears the answer through
 * `locations.dialog.saved`. Neither module names the other's classes.
 */
class LocationController extends Controller
{
    /**
     * The window's body. With `place_id` — a town just picked in the window,
     * not yet saved — so the sections below the town (the shops in it) follow
     * the choice before anything is stored.
     */
    public function dialog(Request $request)
    {
        $placeId = (int) $request->query('place_id', 0)
            ?: (int) hook_filter('storefront.visitor_place', null);
        $place = $placeId ? Place::query()->available()->with('state')->find($placeId) : null;

        $countries = Country::query()->where('enabled', true)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('m_locations_places as p')
                ->whereColumn('p.country', 'm_locations_countries.code')->where('p.enabled', true))
            ->orderBy('code')->get();

        return view('torgash.locations::dialog', [
            'place' => $place,
            'countries' => $countries,
            // The shop's own country first (core's default_country, or what a
            // module knows of the visitor), not whichever code sorts first —
            // a Kyiv shop opened the window on Russia.
            'country' => $place?->country ?? (string) $request->query('country', $this->startingCountry($countries)),
            'sections' => (string) hook_filter('locations.dialog.sections', '', ['place_id' => $place?->id]),
        ]);
    }

    /** @param  \Illuminate\Support\Collection<int, Country>  $countries */
    protected function startingCountry($countries): string
    {
        $own = \App\Core\Support\Phones::defaultCountry();

        return $own && $countries->contains('code', $own) ? $own : (string) ($countries->first()?->code ?? '');
    }

    /** The town nearest to where the browser says it is — for "update by GPS" and the town prompt. */
    public function nearest(Request $request)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $place = Place::query()->available()->nearest((float) $data['lat'], (float) $data['lng'])->first();

        return response()->json($place ? ['id' => $place->id, 'label' => $place->label(), 'name' => $place->t('name')] : ['id' => null]);
    }

    /**
     * The answer. The town is said to whoever keeps it; the rest of the form
     * (a shop, a switch) goes to whoever asked for it. A page that changes with
     * the answer is reloaded, so HTMX is told to refresh.
     */
    public function save(Request $request)
    {
        $data = $request->validate(['place_id' => ['required', 'integer']]);
        $place = Place::query()->available()->findOrFail($data['place_id']);

        hook_action('storefront.place_chosen', ['place_id' => $place->id, 'source' => 'visitor', 'explicit' => true]);
        hook_action('locations.dialog.saved', ['request' => $request, 'place_id' => $place->id]);

        if ($request->header('HX-Request')) {
            return response('', 204)->header('HX-Refresh', 'true');
        }

        return back();
    }
}
