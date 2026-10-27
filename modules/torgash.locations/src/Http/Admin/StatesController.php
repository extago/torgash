<?php

namespace Torgash\Locations\Http\Admin;

use App\Http\Controllers\Controller;
use App\Models\Language;
use Illuminate\Http\Request;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;

class StatesController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request);

        $counts = $this->applyFilters(State::query(), ['status' => ''] + $filters)->toBase()
            ->selectRaw('count(*) as total, count(*) filter (where enabled) as enabled')->first();

        $states = $this->applyFilters(State::query(), $filters)
            ->orderBy('country')
            ->orderBy('code')
            ->paginate(100)
            ->withQueryString();

        return view('torgash.locations::admin.states', [
            'states' => $states,
            'filters' => $filters,
            'countries' => State::query()->distinct()->orderBy('country')->pluck('country'),
            'placeCounts' => Place::query()
                ->selectRaw('state_id, count(*) as total')
                ->whereNotNull('state_id')
                ->groupBy('state_id')
                ->pluck('total', 'state_id'),
            'countryNames' => Country::pluck('name', 'code'),
            'statusCounts' => [
                '' => (int) $counts->total,
                'enabled' => (int) $counts->enabled,
                'disabled' => (int) $counts->total - (int) $counts->enabled,
            ],
        ]);
    }

    /** @return array{q: string, country: string, status: string} */
    protected function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->get('q', '')),
            'country' => (string) $request->get('country', ''),
            'status' => in_array($request->get('status'), ['enabled', 'disabled'], true) ? (string) $request->get('status') : '',
        ];
    }

    /** Shared with "apply to everything found" — one definition, or the two drift. */
    protected function applyFilters($query, array $filters)
    {
        return $query
            ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($q) => $q
                ->whereRaw('lower(name::text) like ?', ['%'.mb_strtolower($filters['q']).'%'])
                ->orWhere('code', 'ilike', $filters['q'].'%')))
            ->when($filters['country'] !== '', fn ($query) => $query->where('country', $filters['country']))
            ->when($filters['status'] === 'enabled', fn ($query) => $query->where('enabled', true))
            ->when($filters['status'] === 'disabled', fn ($query) => $query->where('enabled', false));
    }

    public function toggle(State $state)
    {
        $state->update(['enabled' => ! $state->enabled, 'edited' => true]);

        return view('torgash.locations::admin.partials.state-row', [
            'state' => $state,
            'placeCount' => Place::where('state_id', $state->id)->count(),
        ]);
    }

    public function create(Request $request)
    {
        return view('torgash.locations::admin.state-form', [
            'state' => new State(['enabled' => true, 'country' => strtoupper((string) $request->get('country', ''))]),
            'languages' => $this->languages(new State),
            'placeCount' => 0,
        ]);
    }

    public function store(Request $request)
    {
        // Made by a person, so the importer never rewrites it.
        $state = State::create($this->data($request) + ['source' => 'manual', 'edited' => true]);

        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.locations.regions')
            : redirect()->route('admin.locations.regions.edit', $state))->with('status', __('Region added.'));
    }

    public function edit(State $state)
    {
        return view('torgash.locations::admin.state-form', [
            'state' => $state,
            'languages' => $this->languages($state),
            'placeCount' => Place::where('state_id', $state->id)->count(),
        ]);
    }

    public function update(Request $request, State $state)
    {
        $state->update($this->data($request) + ['edited' => true]);

        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.locations.regions', ['country' => $state->country])
            : redirect()->route('admin.locations.regions.edit', $state))->with('status', __('Region updated.'));
    }

    /**
     * Deleting a region does NOT delete its towns.
     *
     * They lose their region (the foreign key nulls out) and keep working —
     * an address or an order pointing at one must not break because somebody
     * tidied up the region list. The form says how many that will be.
     */
    public function destroy(State $state)
    {
        $country = $state->country;
        $detached = Place::where('state_id', $state->id)->count();

        $state->delete();

        return redirect()->route('admin.locations.regions', ['country' => $country])
            ->with('status', $detached > 0
                ? __('Region deleted; :count places kept without a region.', ['count' => number_format($detached, 0, '.', ' ')])
                : __('Region deleted.'));
    }

    protected function data(Request $request): array
    {
        $fallback = content_base_locale();

        $validated = $request->validate([
            'country' => 'required|string|size:2',
            'code' => ['required', 'string', 'max:16'],
            'names' => 'required|array',
            'names.*' => 'nullable|string|max:190',
            "names.{$fallback}" => 'required|string|max:190',
        ]);

        return [
            'country' => strtoupper($validated['country']),
            'code' => $validated['code'],
            'name' => array_map('trim', array_filter(
                $validated['names'],
                fn (?string $value) => $value !== null && trim($value) !== '',
            )),
            'enabled' => $request->boolean('enabled'),
        ];
    }

    /** @return array<string, string> code => label; see PlacesController for why. */
    protected function languages(State $state): array
    {
        $languages = Language::where('enabled', true)->orderBy('sort')->get()
            ->mapWithKeys(fn (Language $language) => [$language->code => $language->native_name ?: $language->name])
            ->all();

        foreach (array_keys($state->name ?? []) as $code) {
            $languages[$code] ??= strtoupper((string) $code);
        }

        return $languages;
    }

    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|in:enable,disable',
            'ids' => 'array',
            'ids.*' => 'integer',
            'all' => 'boolean',
        ]);

        $query = State::query();

        if ($request->boolean('all')) {
            $this->applyFilters($query, $this->filters($request));
        } else {
            $query->whereIn('id', $validated['ids'] ?? []);
        }

        $affected = $query->update(['enabled' => $validated['action'] === 'enable']);

        return back()->with('status', __(':count regions updated.', ['count' => $affected]));
    }
}
