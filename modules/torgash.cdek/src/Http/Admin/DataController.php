<?php

namespace Torgash\Cdek\Http\Admin;

use App\Core\Settings\SettingsManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use Torgash\Cdek\Api\Client;
use Torgash\Cdek\Jobs\MatchPlaces;
use Torgash\Cdek\Jobs\SyncCities;
use Torgash\Cdek\Jobs\SyncPoints;
use Torgash\Cdek\Models\City;
use Torgash\Cdek\Models\PlaceMap;
use Torgash\Cdek\Models\Point;
use Torgash\Cdek\Models\Sync;
use Torgash\Cdek\Sync\CitySync;
use Torgash\Cdek\Sync\MapSnapshot;
use Torgash\Cdek\Sync\PlaceMatcher;
use Torgash\Locations\Models\Place;

/**
 * The carrier's data screen: pull its directory, reconcile it with ours, and
 * deal with what did not reconcile — the same screen as Nova Poshta's, because
 * it is the same job. A place with no mapping is simply not offered CDEK, so
 * "how many are unmatched" is the number this page exists to show.
 */
class DataController extends Controller
{
    /** Which slice of the registry the list shows. */
    public const VIEWS = ['unmatched', 'manual', 'all'];

    public const SORTS = ['place', 'region', 'population'];

    public function index(Request $request)
    {
        $places = $this->places($request);

        return view('torgash.cdek::admin.data', [
            'configured' => Client::configured(),
            'testMode' => (bool) setting('torgash.cdek', 'test_mode', false),
            'counts' => $this->counts(),
            'citiesSyncedAt' => Sync::find('cities')?->synced_at,
            'pointsSyncedAt' => Sync::find('points')?->synced_at,
            'sender' => $this->sender(),
            'byMethod' => PlaceMap::query()
                ->selectRaw('matched_by, count(*) as total')
                ->groupBy('matched_by')
                ->pluck('total', 'matched_by')
                ->all(),
            'search' => trim((string) $request->query('q', '')),
            'show' => $this->show($request),
            'order' => $this->order($request),
            'places' => $places,
            'maps' => $this->mapsFor($places->getCollection()),
        ]);
    }

    public function sync()
    {
        if (! Client::configured()) {
            return back()->withErrors(['account' => __('Set the account and secure password first.')]);
        }

        SyncCities::start();

        return back()->with('status', __('Syncing the directory in the background — follow it under Background tasks.'));
    }

    public function match()
    {
        if (City::count() === 0) {
            return back()->withErrors(['sync' => __('Sync the directory first — there is nothing to match against.')]);
        }

        MatchPlaces::start();

        return back()->with('status', __('Matching in the background — follow it under Background tasks.'));
    }

    /**
     * Every point of the country at once. Optional: a city nobody has fetched
     * is fetched by the first checkout that names it.
     */
    public function syncPoints()
    {
        if (! Client::configured()) {
            return back()->withErrors(['account' => __('Set the account and secure password first.')]);
        }

        SyncPoints::start();

        return back()->with('status', __('Fetching pickup points in the background — follow it under Background tasks.'));
    }

    /**
     * One live call, so an admin who has just pasted the keys learns whether
     * they work here rather than from a failed background job later.
     */
    public function check(Client $client)
    {
        try {
            $client->forgetToken();
            $client->token();
        } catch (Throwable $e) {
            return back()->withErrors(['account' => __('CDEK refused the keys: :error', ['error' => $e->getMessage()])]);
        }

        return back()->with('status', __('The keys work.'));
    }

    /**
     * The city parcels leave from, picked from the carrier's own directory —
     * a numeric code in a settings field is something an admin can only get
     * wrong.
     */
    public function senderSearch(Request $request)
    {
        $query = trim((string) $request->query('q', ''));
        $needle = mb_strtolower(str_replace(['ё', 'Ё'], ['е', 'Е'], $query));

        return view('torgash.cdek::admin.partials.sender', [
            'query' => $query,
            'matches' => $query === '' ? collect() : City::query()
                ->whereIn('country', CitySync::countries())
                ->whereRaw("replace(lower(name), 'ё', 'е') like ?", [$needle.'%'])
                // The big city before the village of the same name.
                ->orderByRaw('case when lower(name) = ? then 0 else 1 end', [$needle])
                ->orderBy('name')
                ->limit(15)
                ->get(),
            'sender' => $this->sender(),
        ]);
    }

    public function setSender(Request $request, SettingsManager $settings)
    {
        $code = (int) $request->input('city_code', 0);

        abort_unless($code === 0 || City::whereKey($code)->exists(), 404);

        $settings->set('torgash.cdek', 'sender_city_code', $code);

        return view('torgash.cdek::admin.partials.sender', [
            'query' => '',
            'matches' => collect(),
            'sender' => $this->sender(),
        ]);
    }

    protected function sender(): ?City
    {
        $code = (int) setting('torgash.cdek', 'sender_city_code', 0);

        return $code > 0 ? City::find($code) : null;
    }

    /** The mapping as a CSV to keep, streamed. */
    public function exportMap(MapSnapshot $snapshot)
    {
        $name = 'cdek-map-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($snapshot) {
            $handle = fopen('php://output', 'w');
            // Excel reads UTF-8 as its local codepage without this.
            fwrite($handle, "\u{FEFF}");
            fputcsv($handle, MapSnapshot::COLUMNS);

            foreach ($snapshot->rows() as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function importMap(Request $request, MapSnapshot $snapshot)
    {
        $request->validate(['file' => 'required|file|max:20480']);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);

        // Checked rather than trusted: an unrelated CSV must not rewrite the
        // mapping with whatever its first column holds — Nova Poshta's file
        // included, whose header differs in its third column.
        $header = array_map(fn ($cell) => trim(ltrim((string) $cell, "\u{FEFF}")), $header ?: []);

        if (array_slice($header, 0, 3) !== array_slice(MapSnapshot::COLUMNS, 0, 3)) {
            fclose($handle);

            return back()->withErrors(['file' => __('That is not a CDEK mapping file — its columns must start with :columns.', [
                'columns' => implode(', ', array_slice(MapSnapshot::COLUMNS, 0, 3)),
            ])]);
        }

        $result = $snapshot->restore((function () use ($handle) {
            while (($row = fgetcsv($handle)) !== false) {
                yield $row;
            }

            fclose($handle);
        })());

        return back()->with('status', __(':restored mappings restored, :unknown skipped (no such place here).', $result));
    }

    public function showRow(Place $place)
    {
        return $this->row($place, PlaceMap::with('city')->find($place->id));
    }

    public function candidates(Request $request, Place $place, PlaceMatcher $matcher)
    {
        $query = trim((string) $request->query('q', ''));

        return view('torgash.cdek::admin.partials.candidates', [
            'place' => $place,
            'query' => $query,
            'candidates' => $matcher->candidatesFor($place, $query),
        ]);
    }

    public function bind(Request $request, Place $place)
    {
        $city = City::findOrFail((int) $request->input('city_code'));

        $map = PlaceMap::updateOrCreate(['place_id' => $place->id], [
            'city_code' => $city->code,
            // Never overwritten by a later automatic run: a human decided this.
            'matched_by' => 'manual',
            'confidence' => 100,
            'checked_at' => now(),
        ]);

        return $this->row($place, $map->load('city'), justBound: true);
    }

    /** Automatic mappings come back on the next matching run — and the confirm says so. */
    public function unbind(Place $place)
    {
        PlaceMap::whereKey($place->id)->delete();

        return $this->row($place, null);
    }

    protected function row(Place $place, ?PlaceMap $map, bool $justBound = false)
    {
        return view('torgash.cdek::admin.partials.place-row', [
            'place' => $place->load('state'),
            'map' => $map,
            'justBound' => $justBound,
        ]);
    }

    /** @return array<string, int> */
    protected function counts(): array
    {
        $places = DB::table('m_locations_places')->whereIn('country', CitySync::countries())->count();
        $mapped = PlaceMap::count();

        return [
            'cities' => City::count(),
            'points' => Point::count(),
            'point_cities' => Point::query()->distinct()->count('city_code'),
            'places' => $places,
            'mapped' => $mapped,
            'unmatched' => max(0, $places - $mapped),
        ];
    }

    protected function show(Request $request): string
    {
        return in_array($request->query('show'), self::VIEWS, true) ? (string) $request->query('show') : 'unmatched';
    }

    /** @return array{sort: string, dir: string} */
    protected function order(Request $request): array
    {
        $sort = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'population';
        $default = $sort === 'population' ? 'desc' : 'asc';
        $dir = in_array($request->query('dir'), ['asc', 'desc'], true) ? (string) $request->query('dir') : $default;

        return ['sort' => $sort, 'dir' => $dir];
    }

    /**
     * Three slices, because they are three jobs: the unmatched is the work to
     * be done, "by hand" is what a human already decided, and "all" is how a
     * mapping that went to the wrong namesake gets found at all.
     */
    protected function places(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $show = $this->show($request);
        ['sort' => $sort, 'dir' => $dir] = $this->order($request);
        $locale = admin_content_locale();

        return Place::query()
            ->select('m_locations_places.*')
            // Qualified throughout: sorting by region joins the states table,
            // which has a `country` of its own.
            ->whereIn('m_locations_places.country', CitySync::countries())
            ->when($show === 'unmatched', fn ($query) => $query
                ->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('m_cdek_place_map')
                    ->whereColumn('m_cdek_place_map.place_id', 'm_locations_places.id')))
            ->when($show === 'manual', fn ($query) => $query
                ->whereExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('m_cdek_place_map')
                    ->whereColumn('m_cdek_place_map.place_id', 'm_locations_places.id')
                    ->where('m_cdek_place_map.matched_by', 'manual')))
            ->when($search !== '', fn ($query) => $query
                ->whereRaw('m_locations_places.search_text like ?', ['%'.mb_strtolower($search).'%']))
            ->when($sort === 'place', fn ($query) => $query
                ->orderByRaw("lower(coalesce(m_locations_places.name->>?, m_locations_places.name->>'en', m_locations_places.name->>'ru')) {$dir}", [$locale]))
            ->when($sort === 'region', fn ($query) => $query
                ->leftJoin('m_locations_states as cdek_state', 'cdek_state.id', '=', 'm_locations_places.state_id')
                ->orderByRaw("lower(coalesce(cdek_state.name->>?, cdek_state.name->>'en', cdek_state.name->>'ru')) {$dir}", [$locale]))
            ->when($sort === 'population', fn ($query) => $query->orderBy('m_locations_places.population', $dir))
            // A stable tiebreaker, or page 2 can repeat a row from page 1.
            ->orderBy('m_locations_places.id')
            ->with('state')
            ->paginate(25)
            ->withQueryString();
    }

    /** Mappings for the page's places in one query — not one per row. */
    protected function mapsFor($places)
    {
        return PlaceMap::with('city')
            ->whereIn('place_id', $places->pluck('id'))
            ->get()
            ->keyBy('place_id');
    }
}
