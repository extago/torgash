<?php

namespace Torgash\Locations\Http\Admin;

use App\Http\Controllers\Controller;
use App\Models\Language;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;

class PlacesController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->get('q', '')),
            'country' => (string) $request->get('country', ''),
            'state' => (string) $request->get('state', ''),
            'type' => (string) $request->get('type', ''),
            'status' => (string) $request->get('status', ''),
        ];

        // Regions are offered for the chosen country only — a flat list of
        // every region of every imported pack is 110 entries and climbing.
        $states = State::when($filters['country'] !== '', fn ($query) => $query->where('country', $filters['country']))
            ->orderBy('country')
            ->get();

        // A region left in the URL from a previous country would otherwise
        // filter the list down to nothing with no visible reason why.
        if ($filters['state'] !== '' && ! $states->contains('id', (int) $filters['state'])) {
            $filters['state'] = '';
        }

        // Every status tab's count under the other filters, in one pass over
        // the table — it is 190 000 rows, so one query and not six.
        // reorder(): the search scope orders by size, and an ORDER BY beside
        // aggregates is a grouping error in Postgres — searching the list 500'd.
        $counts = $this->applyFilters(Place::query(), ['status' => ''] + $filters)->reorder()->toBase()->selectRaw(
            'count(*) as total, count(*) filter (where enabled) as enabled, count(*) filter (where edited) as edited,'
            ." count(*) filter (where source = 'manual') as manual, count(*) filter (where missing_in_source) as missing"
        )->first();

        $places = $this->applyFilters(Place::with('state'), $filters)
            // Without a search term the list has no natural order, and biggest
            // first is the only one that puts recognisable places on page one.
            ->when($filters['q'] === '', fn ($query) => $query->orderByDesc('population'))
            ->paginate(50)
            ->withQueryString();

        return view('torgash.locations::admin.index', [
            'places' => $places,
            'filters' => $filters,
            'states' => $states,
            'types' => Place::TYPES,
            'countries' => Place::query()->distinct()->orderBy('country')->pluck('country'),
            'statusCounts' => [
                '' => (int) $counts->total,
                'enabled' => (int) $counts->enabled,
                'disabled' => (int) $counts->total - (int) $counts->enabled,
                'edited' => (int) $counts->edited,
                'manual' => (int) $counts->manual,
                'missing' => (int) $counts->missing,
            ],
            'totals' => [
                'places' => Place::count(),
                'disabled' => Place::where('enabled', false)->count(),
                'manual' => Place::where('source', 'manual')->count(),
                'missing' => Place::where('missing_in_source', true)->count(),
            ],
        ]);
    }

    public function create()
    {
        $place = new Place(['enabled' => true, 'type' => 'settlement', 'population' => 0]);

        return view('torgash.locations::admin.form', [
            'place' => $place,
            'states' => State::orderBy('country')->get(),
            'languages' => $this->languages($place),
        ]);
    }

    /**
     * The one place the filters are expressed.
     *
     * Shared by the list and by "apply to everything found", which must select
     * exactly the same rows — a bulk delete that interprets a filter even
     * slightly differently from the list that showed it is how the wrong
     * hundred thousand rows get deleted.
     *
     * @param  array{q: string, country: string, state: string, type: string, status: string}  $filters
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['q'] !== '', fn ($query) => $query->search($filters['q']))
            ->when($filters['country'] !== '', fn ($query) => $query->where('country', $filters['country']))
            ->when($filters['state'] !== '', fn ($query) => $query->where('state_id', $filters['state']))
            ->when($filters['type'] !== '', fn ($query) => $query->where('type', $filters['type']))
            ->when($filters['status'] === 'enabled', fn ($query) => $query->where('enabled', true))
            ->when($filters['status'] === 'disabled', fn ($query) => $query->where('enabled', false))
            ->when($filters['status'] === 'edited', fn ($query) => $query->where('edited', true))
            ->when($filters['status'] === 'manual', fn ($query) => $query->where('source', 'manual'))
            ->when($filters['status'] === 'missing', fn ($query) => $query->where('missing_in_source', true));
    }

    /**
     * The languages a name or a district can be written in: the shop's own,
     * plus any the imported pack happens to carry. The second half matters — a
     * form that only shows the shop's languages would drop the rest on the
     * first save. That includes "und", which is what a pack from before
     * districts had languages leaves behind (see PackImporter): it is a real
     * value someone may want to correct, and hiding it would delete it.
     *
     * @return array<string, string> code => label
     */
    protected function languages(Place $place): array
    {
        $languages = Language::where('enabled', true)->orderBy('sort')->get()
            ->mapWithKeys(fn (Language $language) => [$language->code => $language->native_name ?: $language->name])
            ->all();

        foreach ([...array_keys($place->name ?? []), ...array_keys($place->district ?? [])] as $code) {
            $languages[$code] ??= strtoupper((string) $code);
        }

        return $languages;
    }

    public function store(Request $request)
    {
        // Added by a person, so it belongs to this shop: no geonameid, and the
        // importer will never touch it.
        $place = Place::create($this->data($request) + ['source' => 'manual', 'edited' => true]);

        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.locations.index')
            : redirect()->route('admin.locations.edit', $place))->with('status', __('Place added.'));
    }

    public function edit(Place $place)
    {
        return view('torgash.locations::admin.form', [
            'place' => $place,
            'states' => State::orderBy('country')->get(),
            'languages' => $this->languages($place),
        ]);
    }

    public function update(Request $request, Place $place)
    {
        // Editing marks the row as the shop's own — that flag is what makes the
        // next pack import leave it alone.
        $place->update($this->data($request) + ['edited' => true]);

        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.locations.index', $request->only('q', 'country', 'state', 'type', 'status', 'page'))
            : redirect()->route('admin.locations.edit', $place))->with('status', __('Place updated.'));
    }

    /** Toggled straight from the list; the row comes back re-rendered for HTMX. */
    public function toggle(Request $request, Place $place)
    {
        $place->update(['enabled' => ! $place->enabled, 'edited' => true]);

        return view('torgash.locations::admin.partials.row', ['place' => $place->load('state')]);
    }

    /**
     * Bulk switch or delete, over the ticked rows or over every row the filter
     * matched — the second is what "delete all 192 000 Russian places" needs,
     * and no amount of ticking would get there.
     *
     * Deleting goes through a single statement rather than Eloquent per row:
     * at this size the difference is minutes.
     */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|in:enable,disable,delete',
            'ids' => 'array',
            'ids.*' => 'integer',
            'all' => 'boolean',
        ]);

        $query = Place::query();

        if ($request->boolean('all')) {
            $this->applyFilters($query, [
                'q' => trim((string) $request->get('q', '')),
                'country' => (string) $request->get('country', ''),
                'state' => (string) $request->get('state', ''),
                'type' => (string) $request->get('type', ''),
                'status' => (string) $request->get('status', ''),
            ]);
        } else {
            $query->whereIn('id', $validated['ids'] ?? []);
        }

        $affected = $validated['action'] === 'delete'
            ? $query->delete()
            : $query->update(['enabled' => $validated['action'] === 'enable', 'edited' => true]);

        $message = $validated['action'] === 'delete'
            ? __(':count places deleted.', ['count' => $affected])
            : __(':count places updated.', ['count' => $affected]);

        return redirect()
            ->route('admin.locations.index', $request->only('q', 'country', 'state', 'type', 'status'))
            ->with('status', $message);
    }

    public function destroy(Request $request, Place $place)
    {
        $place->delete();

        return redirect()->route('admin.locations.index', $request->only('q', 'country', 'state', 'type', 'status', 'page'))
            ->with('status', __('Place deleted.'));
    }

    protected function data(Request $request): array
    {
        $fallback = content_base_locale();

        $validated = $request->validate([
            'names' => 'required|array',
            'names.*' => 'nullable|string|max:190',
            "names.{$fallback}" => 'required|string|max:190',
            'country' => 'required|string|size:2',
            'state_id' => ['nullable', Rule::exists('m_locations_states', 'id')],
            'districts' => 'nullable|array',
            'districts.*' => 'nullable|string|max:190',
            'forms' => 'nullable|array',
            'forms.*' => 'nullable|array',
            'forms.*.*' => 'nullable|string|max:190',
            'type' => 'required|string|max:24',
            'aliases' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'population' => 'nullable|integer|min:0',
            'postal_from' => 'nullable|string|max:16',
            'postal_to' => 'nullable|string|max:16',
        ]);

        return [
            // Empty boxes are dropped rather than stored as "", so a language
            // left blank falls back through t() instead of rendering nothing.
            'name' => $this->filled($validated['names']),
            'country' => strtoupper($validated['country']),
            'state_id' => ($validated['state_id'] ?? '') ?: null,
            // Null rather than an empty object, so "no district" is one value
            // in the column and not two.
            'district' => $this->filled($validated['districts'] ?? []) ?: null,
            // Only the corrections: an empty box means "the rules answer this
            // one", which is the state 192 000 imported rows are in. Storing
            // the derived word instead would make every guess look like the
            // shop's own decision and freeze it there.
            'forms' => $this->filledForms($validated['forms'] ?? []) ?: null,
            'type' => $validated['type'],
            'aliases' => ($validated['aliases'] ?? '') ?: null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'population' => (int) ($validated['population'] ?? 0),
            'postal_from' => ($validated['postal_from'] ?? '') ?: null,
            'postal_to' => ($validated['postal_to'] ?? '') ?: null,
            'enabled' => $request->boolean('enabled'),
        ];
    }

    /**
     * The case forms somebody actually typed, per language — an empty language
     * disappears with its boxes.
     *
     * @param  array<string, array<int|string, string|null>>  $values
     * @return array<string, array<int, string>>
     */
    protected function filledForms(array $values): array
    {
        $forms = [];

        foreach ($values as $locale => $cases) {
            foreach ($this->filled((array) $cases) as $case => $word) {
                if ((int) $case > 0) {
                    $forms[$locale][(int) $case] = $word;
                }
            }
        }

        return $forms;
    }

    /**
     * The languages a translated field was actually written in.
     *
     * @param  array<string, string|null>  $values
     * @return array<string, string>
     */
    protected function filled(array $values): array
    {
        return array_map('trim', array_filter(
            $values,
            fn (?string $value) => $value !== null && trim($value) !== '',
        ));
    }
}
