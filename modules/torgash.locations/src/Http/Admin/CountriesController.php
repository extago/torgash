<?php

namespace Torgash\Locations\Http\Admin;

use App\Http\Controllers\Controller;
use App\Models\Language;
use Illuminate\Http\Request;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;
use Torgash\Locations\PackImporter;

class CountriesController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request);

        $countries = $this->applyFilters(Country::query(), $filters)
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        // Each status tab counts what the search leaves, in one query.
        $counts = $this->applyFilters(Country::query(), ['status' => ''] + $filters)->toBase()
            ->selectRaw('count(*) as total, count(*) filter (where enabled) as enabled')->first();

        // Which countries actually have a pack imported — the ones worth
        // switching on, and the only place the count is visible at a glance.
        $places = Place::query()->selectRaw('country, count(*) as total')->groupBy('country')->pluck('total', 'country');

        return view('torgash.locations::admin.countries', [
            'countries' => $countries,
            'filters' => $filters,
            'placeCounts' => $places,
            'totals' => [
                'countries' => Country::count(),
                'enabled' => Country::where('enabled', true)->count(),
            ],
            'statusCounts' => [
                '' => (int) $counts->total,
                'enabled' => (int) $counts->enabled,
                'disabled' => (int) $counts->total - (int) $counts->enabled,
            ],
            'pack' => is_file($this->countriesFile()) ? $this->countriesFile() : null,
        ]);
    }

    /** In-place switch from the list: answers with the row. */
    public function toggle(Country $country)
    {
        $country->update(['enabled' => ! $country->enabled, 'edited' => true]);

        return view('torgash.locations::admin.partials.country-row', [
            'country' => $country,
            'placeCount' => Place::where('country', $country->code)->count(),
        ]);
    }

    /** @return array{q: string, status: string} */
    protected function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->get('q', '')),
            'status' => in_array($request->get('status'), ['enabled', 'disabled'], true) ? (string) $request->get('status') : '',
        ];
    }

    /**
     * The one place the list's filters are expressed — shared with "apply to
     * everything found", which must select exactly the rows the list showed.
     */
    protected function applyFilters($query, array $filters)
    {
        return $query
            ->when($filters['q'] !== '', fn ($query) => $query
                ->where(fn ($q) => $q
                    ->whereRaw('lower(name::text) like ?', ['%'.mb_strtolower($filters['q']).'%'])
                    ->orWhere('code', 'ilike', $filters['q'].'%')))
            ->when($filters['status'] === 'enabled', fn ($query) => $query->where('enabled', true))
            ->when($filters['status'] === 'disabled', fn ($query) => $query->where('enabled', false));
    }

    /** Imports the world list; small enough to do in the request. */
    public function import(PackImporter $importer)
    {
        if (! is_file($this->countriesFile())) {
            return back()->withErrors(['pack' => __('countries.csv is not in the packs folder.')]);
        }

        $counts = $importer->importCountries($this->countriesFile());

        return back()->with('status', __(':created added, :updated updated.', $counts));
    }

    /**
     * Bulk switch, over the ticked rows or over everything the filter matched.
     *
     * "Everything matched" is the point: a shop that ships to two countries
     * turns all 252 off in one go and then turns two on, rather than clicking
     * 250 times.
     */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|in:enable,disable',
            'ids' => 'array',
            'ids.*' => 'integer',
            'all' => 'boolean',
        ]);

        $query = Country::query();

        if ($request->boolean('all')) {
            // The list's own filter, not a second copy of it: the copy that
            // lived here matched the search by name only, so "all found" for
            // a search by code left out rows the list was showing.
            $this->applyFilters($query, $this->filters($request));
        } else {
            $query->whereIn('id', $validated['ids'] ?? []);
        }

        $affected = $query->update(['enabled' => $validated['action'] === 'enable']);

        return back()->with('status', __(':count countries updated.', ['count' => $affected]));
    }

    public function create()
    {
        return view('torgash.locations::admin.country-form', [
            'country' => new Country(['enabled' => true]),
            'languages' => $this->languages(new Country),
            'placeCount' => 0,
        ]);
    }

    public function store(Request $request)
    {
        $country = Country::create($this->data($request) + ['source' => 'manual', 'edited' => true]);

        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.locations.countries')
            : redirect()->route('admin.locations.countries.edit', $country))->with('status', __('Country added.'));
    }

    public function edit(Country $country)
    {
        return view('torgash.locations::admin.country-form', [
            'country' => $country,
            'languages' => $this->languages($country),
            'placeCount' => Place::where('country', $country->code)->count(),
        ]);
    }

    public function update(Request $request, Country $country)
    {
        // Marks it as the shop's own: from here the pack only fills gaps.
        $country->update($this->data($request) + ['edited' => true]);

        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.locations.countries')
            : redirect()->route('admin.locations.countries.edit', $country))->with('status', __('Country updated.'));
    }

    /**
     * Removes the country from the list, not its towns.
     *
     * They stay in the registry — an order may point at one — but stop being
     * offered, because Place::available() treats a country that is not listed
     * as one the shop does not serve.
     */
    public function destroy(Country $country)
    {
        $code = $country->code;
        $country->delete();

        return redirect()->route('admin.locations.countries')->with('status', __('Country :code removed from the list.', ['code' => $code]));
    }

    protected function data(Request $request): array
    {
        $fallback = content_base_locale();

        $validated = $request->validate([
            'code' => 'required|string|size:2',
            'code3' => 'nullable|string|size:3',
            'numeric_code' => 'nullable|string|max:3',
            'continent' => 'nullable|string|size:2',
            'currency' => 'nullable|string|size:3',
            'phone' => 'nullable|string|max:16',
            'postal_format' => 'nullable|string|max:64',
            'postal_regex' => 'nullable|string|max:190',
            'names' => 'required|array',
            'names.*' => 'nullable|string|max:190',
            "names.{$fallback}" => 'required|string|max:190',
        ]);

        return [
            'code' => strtoupper($validated['code']),
            'code3' => isset($validated['code3']) ? strtoupper($validated['code3']) : null,
            'numeric_code' => ($validated['numeric_code'] ?? '') ?: null,
            'continent' => isset($validated['continent']) ? strtoupper($validated['continent']) : null,
            'currency' => isset($validated['currency']) ? strtoupper($validated['currency']) : null,
            'phone' => ($validated['phone'] ?? '') ?: null,
            'postal_format' => ($validated['postal_format'] ?? '') ?: null,
            'postal_regex' => ($validated['postal_regex'] ?? '') ?: null,
            'name' => array_map('trim', array_filter(
                $validated['names'],
                fn (?string $value) => $value !== null && trim($value) !== '',
            )),
            'enabled' => $request->boolean('enabled'),
        ];
    }

    /** @return array<string, string> code => label; see PlacesController for why. */
    protected function languages(Country $country): array
    {
        $languages = Language::where('enabled', true)->orderBy('sort')->get()
            ->mapWithKeys(fn (Language $language) => [$language->code => $language->native_name ?: $language->name])
            ->all();

        foreach (array_keys($country->name ?? []) as $code) {
            $languages[$code] ??= strtoupper((string) $code);
        }

        return $languages;
    }

    /**
     * The page that has to be walked through before anything is deleted.
     *
     * There used to be a "delete its places" button in the table, one click and
     * a browser confirm away from destroying 192 000 rows — sitting next to the
     * on/off switches an admin clicks all day. Now it is a separate page that
     * spells out the blast radius and asks for the country code to be typed,
     * because the rows that cannot be brought back by re-importing are the
     * hand-added ones, and those deserve to be counted out loud.
     */
    public function confirmPurge(string $code)
    {
        $code = strtoupper($code);
        $country = Country::where('code', $code)->first();

        return view('torgash.locations::admin.countries-purge', [
            'code' => $code,
            'country' => $country,
            'counts' => [
                'places' => Place::where('country', $code)->count(),
                'states' => State::where('country', $code)->count(),
                'manual' => Place::where('country', $code)->where('source', 'manual')->count(),
                'edited' => Place::where('country', $code)->where('edited', true)->where('source', '!=', 'manual')->count(),
            ],
        ]);
    }

    /**
     * Everything the country has, gone: its places and regions, not the country
     * row itself — that is a fact about the world, not about this shop.
     */
    public function purge(Request $request, PackImporter $importer)
    {
        $validated = $request->validate([
            'code' => 'required|string|size:2',
            'confirm_code' => 'required|string',
        ]);

        $code = strtoupper($validated['code']);

        // Typed, not clicked. A dialog gets dismissed by reflex; a country code
        // has to be read off the screen and entered.
        if (strtoupper(trim($validated['confirm_code'])) !== $code) {
            return back()->withErrors([
                'confirm_code' => __('Type :code exactly to confirm.', ['code' => $code]),
            ]);
        }

        $deleted = $importer->wipe($code);

        return redirect()->route('admin.locations.countries')
            ->with('status', __(':count places deleted for :country.', [
                'count' => number_format($deleted, 0, '.', ' '),
                'country' => $code,
            ]));
    }

    protected function countriesFile(): string
    {
        return base_path((string) setting('torgash.locations', 'packs_path')).'/countries.csv';
    }
}
