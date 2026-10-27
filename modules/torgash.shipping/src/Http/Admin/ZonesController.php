<?php

namespace Torgash\Shipping\Http\Admin;

use App\Http\Controllers\Controller;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Torgash\Shipping\Models\ShippingRate;
use Torgash\Shipping\Models\Zone;
use Torgash\Shipping\Models\ZoneRule;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Shipping\ShippingOptions;

class ZonesController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($request->query('status'), ['enabled', 'disabled'], true) ? (string) $request->query('status') : '',
        ];

        // A shop has a handful of zones: searched in PHP over the name in
        // every language, as the methods list is.
        $all = Zone::with('rules')->orderBy('position')->orderBy('id')->get();
        $found = $filters['q'] === '' ? $all : $all->filter(fn (Zone $zone) => collect((array) $zone->name)
            ->contains(fn ($name) => mb_stripos((string) $name, $filters['q']) !== false));

        $zones = (match ($filters['status']) {
            'enabled' => $found->where('enabled', true),
            'disabled' => $found->where('enabled', false),
            default => $found,
        })->values();

        return view('torgash.shipping::admin.zones.index', [
            'zones' => $zones,
            'filters' => $filters,
            'statusCounts' => [
                '' => $found->count(),
                'enabled' => $found->where('enabled', true)->count(),
                'disabled' => $found->where('enabled', false)->count(),
            ],
            // What each zone actually offers — the other half of "is this zone
            // doing anything". One query for the page.
            'offered' => ShippingRate::with('method')->whereIn('zone_id', $zones->pluck('id'))->get()->groupBy('zone_id'),
            // One batch, not a lookup per place rule across every row.
            'placeNames' => $this->placeNames(
                $zones->flatMap->rules->where('kind', 'place')->pluck('value')->all()
            ),
            'countryNames' => $this->countryNames(),
            'regionNames' => $this->regionNames(),
        ]);
    }

    /** The list's in-place switch: answers with the status cell. */
    public function setStatus(Request $request, Zone $zone)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $zone->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.shipping::admin.zones._status', ['zone' => $zone]);
    }

    /** Several at once, through the models so the audit hears each one. Rates go with a deleted zone. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $zones = Zone::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $zones->each->delete();

            return back()->with('status', trans_choice(':count zone deleted.|:count zones deleted.', $zones->count(), ['count' => $zones->count()]));
        }

        $zones->each(fn (Zone $zone) => $zone->update(['enabled' => $validated['action'] === 'enable']));

        return back()->with('status', trans_choice(':count zone updated.|:count zones updated.', $zones->count(), ['count' => $zones->count()]));
    }

    public function create()
    {
        $zone = new Zone(['enabled' => true, 'position' => 0]);

        return view('torgash.shipping::admin.zones.form', [
            'zone' => $zone,
            'languages' => $this->languages($zone),
        ] + $this->editorData($zone));
    }

    public function store(Request $request)
    {
        $zone = Zone::create($this->data($request));
        $this->syncRules($request, $zone);

        return $this->saved($request, $zone, __('Zone created.'));
    }

    public function edit(Zone $zone)
    {
        return view('torgash.shipping::admin.zones.form', [
            'zone' => $zone,
            'languages' => $this->languages($zone),
            'offered' => $zone->rates()->with('method')->get()
                ->sortBy(fn (ShippingRate $rate) => [$rate->method?->position ?? 0, $rate->method?->id ?? 0])->values(),
        ] + $this->editorData($zone));
    }

    public function update(Request $request, Zone $zone)
    {
        $zone->update($this->data($request));
        $this->syncRules($request, $zone);

        return $this->saved($request, $zone, __('Zone updated.'));
    }

    /** Saving stays on the form; "Save and close" in the gear goes back to the list. */
    protected function saved(Request $request, Zone $zone, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.shipping.zones.index')
            : redirect()->route('admin.shipping.zones.edit', $zone))->with('status', $message);
    }

    public function destroy(Zone $zone)
    {
        $zone->delete();

        return redirect()->route('admin.shipping.zones.index')->with('status', __('Zone deleted.'));
    }

    /**
     * Answers "which zone would this address land in", so an admin can check a
     * zone set-up without placing an order — the question every support ticket
     * about shipping starts with.
     */
    public function test(Request $request, ShippingOptions $options)
    {
        $validated = $request->validate([
            'place_id' => 'nullable|integer',
            'country' => 'nullable|string|size:2',
            'postal' => 'nullable|string|max:16',
        ]);

        $location = ! empty($validated['place_id'])
            ? Location::forPlace((int) $validated['place_id'], $validated['postal'] ?? null)
            : new Location(
                country: $validated['country'] ? strtoupper($validated['country']) : null,
                postal: $validated['postal'] ?? null,
            );

        $zone = $options->zone($location);

        return back()->with('zone_test', [
            'zone' => $zone?->t('name', admin_content_locale()) ?? __('no zone matches this address'),
            'country' => $location->country,
            'state' => $location->state,
            'postal' => $location->postal,
            // The second half of the same question: knowing the zone only helps
            // if you can see what the customer there is actually offered.
            'methods' => $options->for($location, new Package)
                ->map(fn (object $option) => trim(
                    $option->method->t('name', admin_content_locale())
                    .($option->delivery_time ? ' · '.$option->delivery_time : '')
                ))
                ->all(),
        ]);
    }

    /**
     * Everything the composer form needs beyond the zone itself: the pickable
     * countries and regions (from whoever owns the registry — empty when no
     * module does, and then the form falls back to plain text), plus this
     * zone's current coverage split by kind.
     *
     * @return array<string, mixed>
     */
    protected function editorData(Zone $zone): array
    {
        return [
            'offered' => collect(),
            'allCountries' => (array) hook_filter('checkout.location.countries', []),
            'allRegions' => (array) hook_filter('checkout.location.regions', []),
            'covers' => $this->coversFromRules($zone),
        ];
    }

    /**
     * A zone's rules, regrouped into the four things the form edits: a list of
     * country codes, a list of "COUNTRY:CODE" region values, a list of towns
     * (id plus a readable label), and the postal patterns as one block of text.
     *
     * @return array{countries: list<string>, regions: list<string>, towns: list<array{id: int, label: string}>, postal: string}
     */
    protected function coversFromRules(Zone $zone): array
    {
        $rules = $zone->exists ? $zone->rules()->get() : collect();

        $placeNames = $this->placeNames($rules->where('kind', 'place')->pluck('value')->all());

        $postalLines = $rules->map(fn (ZoneRule $rule) => match ($rule->kind) {
            'postal' => $rule->value,
            'postal_prefix' => $rule->value.'*',
            'postal_glob' => $rule->value,
            default => null,
        })->filter()->values();

        return [
            'countries' => $rules->where('kind', 'country')->pluck('value')->values()->all(),
            'regions' => $rules->where('kind', 'state')->pluck('value')->values()->all(),
            'towns' => $rules->where('kind', 'place')->map(fn (ZoneRule $rule) => [
                'id' => (int) $rule->value,
                'label' => $placeNames[(int) $rule->value] ?? $rule->value,
            ])->values()->all(),
            'postal' => $postalLines->implode("\n"),
        ];
    }

    /**
     * Rules are replaced wholesale rather than diffed: a zone has a handful of
     * them, they carry no state worth preserving, and a diff here would be more
     * code than the thing it saves.
     */
    protected function syncRules(Request $request, Zone $zone): void
    {
        $zone->rules()->delete();

        foreach ($this->rulesFromRequest($request) as $rule) {
            $zone->rules()->create($rule);
        }
    }

    /**
     * The four form sections turned into flat rules. Country and region come
     * from the pick lists when a registry is installed and from a textarea
     * fallback ("countries_text" / "regions_text") when one is not; either way
     * they land in the same shape.
     *
     * @return list<array{kind: string, value: string}>
     */
    protected function rulesFromRequest(Request $request): array
    {
        $rules = [];
        $seen = [];

        $add = function (string $kind, string $value) use (&$rules, &$seen) {
            $value = trim($value);

            if ($value === '' || isset($seen[$kind.'|'.$value])) {
                return;
            }

            $seen[$kind.'|'.$value] = true;
            $rules[] = ['kind' => $kind, 'value' => $value];
        };

        foreach ($this->lines($request, 'countries', 'countries_text') as $code) {
            $add('country', $code === '*' ? '*' : strtoupper($code));
        }

        foreach ($this->lines($request, 'regions', 'regions_text') as $value) {
            // "UA:12" — a bare region code cannot be matched against anything.
            if (str_contains($value, ':')) {
                [$country, $code] = explode(':', $value, 2);
                $add('state', strtoupper(trim($country)).':'.trim($code));
            }
        }

        foreach ((array) $request->input('towns', []) as $id) {
            if (ctype_digit((string) $id)) {
                $add('place', (string) $id);
            }
        }

        foreach (preg_split('/[\r\n,]+/', (string) $request->input('postal', '')) as $pattern) {
            $pattern = trim($pattern);

            if ($pattern === '') {
                continue;
            }

            // Only a trailing "*" is a prefix — that one has an index-friendly
            // rule of its own. Anything else with a wildcard is a glob.
            if (Str::endsWith($pattern, '*') && ! Str::contains(rtrim($pattern, '*'), ['*', '?'])) {
                $add('postal_prefix', rtrim($pattern, '*'));
            } elseif (Str::contains($pattern, ['*', '?'])) {
                $add('postal_glob', $pattern);
            } else {
                $add('postal', $pattern);
            }
        }

        return $rules;
    }

    /**
     * A repeatable field's values: the array input if it is there, otherwise
     * the textarea fallback split on newlines and commas.
     *
     * @return list<string>
     */
    protected function lines(Request $request, string $arrayKey, string $textKey): array
    {
        if (is_array($request->input($arrayKey))) {
            return array_values(array_filter(array_map('trim', $request->input($arrayKey))));
        }

        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', (string) $request->input($textKey, ''))
        )));
    }

    /** @param list<string|int> $ids @return array<int, string> */
    protected function placeNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return [];
        }

        return (array) hook_filter('locations.place.names', [], ['ids' => $ids]);
    }

    /** @return array<string, string> code => name */
    protected function countryNames(): array
    {
        $names = [];

        foreach ((array) hook_filter('checkout.location.countries', []) as $country) {
            $names[$country['code']] = $country['name'];
        }

        return $names;
    }

    /** @return array<string, string> "COUNTRY:CODE" => "Country: Region" */
    protected function regionNames(): array
    {
        $names = [];

        foreach ((array) hook_filter('checkout.location.regions', []) as $region) {
            $names[$region['value']] = $region['country_name'].': '.$region['name'];
        }

        return $names;
    }

    protected function data(Request $request): array
    {
        $fallback = content_base_locale();

        $validated = $request->validate([
            'names' => 'required|array',
            'names.*' => 'nullable|string|max:190',
            "names.{$fallback}" => 'required|string|max:190',
            'position' => 'nullable|integer',
        ]);

        return [
            'name' => array_map('trim', array_filter(
                $validated['names'],
                fn (?string $value) => $value !== null && trim($value) !== '',
            )),
            'position' => (int) ($validated['position'] ?? 0),
            'enabled' => $request->boolean('enabled'),
        ];
    }

    /** @return array<string, string> */
    protected function languages(Zone $zone): array
    {
        $languages = Language::where('enabled', true)->orderBy('sort')->get()
            ->mapWithKeys(fn (Language $language) => [$language->code => $language->native_name ?: $language->name])
            ->all();

        foreach (array_keys($zone->name ?? []) as $code) {
            $languages[$code] ??= strtoupper((string) $code);
        }

        return $languages;
    }
}
