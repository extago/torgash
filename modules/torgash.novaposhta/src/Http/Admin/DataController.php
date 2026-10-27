<?php

namespace Torgash\NovaPoshta\Http\Admin;

use App\Core\Settings\SettingsManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use Torgash\Locations\Models\Place;
use Torgash\NovaPoshta\Api\Client;
use Torgash\NovaPoshta\Jobs\MatchPlaces;
use Torgash\NovaPoshta\Jobs\SyncDirectory;
use Torgash\NovaPoshta\Jobs\SyncWarehouses;
use Torgash\NovaPoshta\Models\PlaceMap;
use Torgash\NovaPoshta\Models\Settlement;
use Torgash\NovaPoshta\Models\Sync;
use Torgash\NovaPoshta\Sync\MapSnapshot;
use Torgash\NovaPoshta\Sync\PlaceMatcher;

/**
 * The carrier's data screen: pull its directory, reconcile it with ours, and
 * deal with what did not reconcile.
 *
 * The unmatched list is the point of the page. A place with no mapping is
 * simply not offered Nova Poshta, so "240 towns unmatched" is a number a shop
 * needs to see and be able to fix one row at a time.
 */
class DataController extends Controller
{
    public function index(Request $request)
    {
        $places = $this->places($request);

        return view('torgash.novaposhta::admin.data', [
            'hasKey' => (string) setting('torgash.novaposhta', 'api_key', '') !== '',
            'counts' => $this->counts(),
            'settlementsSyncedAt' => Sync::find('settlements')?->synced_at,
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

    /** Both jobs are queued, so the button returns instantly whatever the catalogue's size. */
    public function sync()
    {
        if ((string) setting('torgash.novaposhta', 'api_key', '') === '') {
            return back()->withErrors(['api_key' => __('Set the API key first.')]);
        }

        SyncDirectory::start();

        return back()->with('status', __('Syncing the directory in the background — follow it under Background tasks.'));
    }

    public function match()
    {
        if (Settlement::count() === 0) {
            return back()->withErrors(['sync' => __('Sync the directory first — there is nothing to match against.')]);
        }

        MatchPlaces::start();

        return back()->with('status', __('Matching in the background — follow it under Background tasks.'));
    }

    /**
     * Fills the branch cache for every mapped town, so the picker never waits
     * for the carrier. Optional: a town nobody has fetched yet is fetched by
     * the first checkout that names it.
     */
    public function syncWarehouses()
    {
        if (PlaceMap::count() === 0) {
            return back()->withErrors([
                'sync' => __('Match places with the registry first — there is nowhere to fetch branches for.'),
            ]);
        }

        SyncWarehouses::start();

        return back()->with('status', __('Fetching branches in the background — follow it under Background tasks.'));
    }

    /**
     * One live call, so an admin who has just pasted a key learns whether it
     * works here rather than from a failed background job ten minutes later.
     */
    public function check(Client $client)
    {
        try {
            $client->call('Common', 'getCargoTypes');
        } catch (Throwable $e) {
            return back()->withErrors(['api_key' => __('Nova Poshta refused the key: :error', ['error' => $e->getMessage()])]);
        }

        return back()->with('status', __('The key works.'));
    }

    /**
     * The town parcels leave from, picked from the carrier's own directory.
     *
     * A UUID in a settings field is something an admin can only get wrong, so
     * the field exists but this is how it gets filled: type a name, see what
     * the carrier calls it, click it.
     */
    public function senderSearch(Request $request)
    {
        $query = trim((string) $request->query('q', ''));

        return view('torgash.novaposhta::admin.partials.sender', [
            'query' => $query,
            'matches' => $query === '' ? collect() : Settlement::query()
                ->where('kind', 'city')
                ->where(fn ($sub) => $sub
                    ->whereRaw('lower(name_uk) like ?', [mb_strtolower($query).'%'])
                    ->orWhereRaw('lower(name_ru) like ?', [mb_strtolower($query).'%']))
                ->orderBy('name_uk')
                ->limit(15)
                ->get(),
            'sender' => $this->sender(),
        ]);
    }

    public function setSender(Request $request, SettingsManager $settings)
    {
        $ref = (string) $request->input('settlement_ref', '');

        // Only a "city" can send: those are the rows the carrier's price call
        // accepts as CitySender.
        abort_unless($ref === '' || Settlement::where('kind', 'city')->whereKey($ref)->exists(), 404);

        $settings->set('torgash.novaposhta', 'sender_city_ref', $ref);

        return view('torgash.novaposhta::admin.partials.sender', [
            'query' => '',
            'matches' => collect(),
            'sender' => $this->sender(),
        ]);
    }

    /** The configured sender town, if it is still in the directory. */
    protected function sender(): ?Settlement
    {
        $ref = trim((string) setting('torgash.novaposhta', 'sender_city_ref', ''));

        return $ref === '' ? null : Settlement::find($ref);
    }

    /**
     * The mapping as a CSV to keep. Streamed, because 26 000 rows built in
     * memory first is a memory limit waiting for a bigger country.
     */
    public function exportMap(MapSnapshot $snapshot)
    {
        $name = 'nova-poshta-map-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($snapshot) {
            $handle = fopen('php://output', 'w');
            // Excel reads UTF-8 as its local codepage without this, and the
            // whole point of the file is that a human can open it.
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

        // Written by us or by a human with a spreadsheet: the header is checked
        // rather than trusted, so an unrelated CSV cannot silently rewrite the
        // mapping with whatever its first column happens to hold.
        if (! $header || trim(ltrim((string) ($header[0] ?? ''), "\u{FEFF}")) !== MapSnapshot::COLUMNS[0]) {
            fclose($handle);

            return back()->withErrors(['file' => __('That is not a Nova Poshta mapping file — its first column must be :column.', [
                'column' => MapSnapshot::COLUMNS[0],
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

    /** The row as it reads — what "Cancel" in the picker asks for. */
    public function showRow(Place $place)
    {
        return $this->row($place, PlaceMap::with('settlement')->find($place->id));
    }

    /** The picker for one place — an HTMX fragment, swapped into its row. */
    public function candidates(Request $request, Place $place, PlaceMatcher $matcher)
    {
        return view('torgash.novaposhta::admin.partials.candidates', [
            'place' => $place,
            'query' => trim((string) $request->query('q', '')),
            'candidates' => $matcher->candidatesFor($place, trim((string) $request->query('q', ''))),
        ]);
    }

    public function bind(Request $request, Place $place)
    {
        $settlement = Settlement::findOrFail($request->input('settlement_ref'));

        $map = PlaceMap::updateOrCreate(['place_id' => $place->id], [
            'settlement_ref' => $settlement->ref,
            'city_ref' => $settlement->city_ref,
            // Never overwritten by a later automatic run: a human decided this.
            'matched_by' => 'manual',
            'confidence' => 100,
            'checked_at' => now(),
        ]);

        return $this->row($place, $map->load('settlement'), justBound: true);
    }

    /**
     * Drops a mapping. Automatic ones come back on the next matching run —
     * which is the honest thing to say rather than pretending it is permanent.
     */
    public function unbind(Place $place)
    {
        PlaceMap::whereKey($place->id)->delete();

        return $this->row($place, null);
    }

    protected function row(Place $place, ?PlaceMap $map, bool $justBound = false)
    {
        return view('torgash.novaposhta::admin.partials.place-row', [
            'place' => $place->load('state'),
            'map' => $map,
            'justBound' => $justBound,
        ]);
    }

    /** @return array<string, int> */
    protected function counts(): array
    {
        $places = DB::table('m_locations_places')->where('country', 'UA')->count();
        $mapped = PlaceMap::count();

        return [
            'settlements' => Settlement::where('kind', 'settlement')->count(),
            'branches' => DB::table('m_np_warehouses')->count(),
            'branch_towns' => DB::table('m_np_warehouses')->distinct()->count('settlement_ref'),
            'cities' => Settlement::where('kind', 'city')->count(),
            'places' => $places,
            'mapped' => $mapped,
            'unmatched' => max(0, $places - $mapped),
        ];
    }

    /** Which slice of the registry the list shows. */
    public const VIEWS = ['unmatched', 'manual', 'all'];

    /** Sortable columns and the SQL each one means. */
    public const SORTS = ['place', 'region', 'population'];

    protected function show(Request $request): string
    {
        return in_array($request->query('show'), self::VIEWS, true)
            ? (string) $request->query('show')
            : 'unmatched';
    }

    /** @return array{sort: string, dir: string} */
    protected function order(Request $request): array
    {
        $sort = in_array($request->query('sort'), self::SORTS, true)
            ? (string) $request->query('sort')
            : 'population';

        // Each column has the direction that is useful first: names read A→Z,
        // population reads biggest-first (those are the towns customers type).
        $default = $sort === 'population' ? 'desc' : 'asc';
        $dir = in_array($request->query('dir'), ['asc', 'desc'], true)
            ? (string) $request->query('dir')
            : $default;

        return ['sort' => $sort, 'dir' => $dir];
    }

    /**
     * The list.
     *
     * Three slices, because they are three different jobs: the unmatched is the
     * work to be done, "by hand" is what a human already decided (and the only
     * rows a re-run will not touch, so the place to check them), and "all" is
     * how a mapping that went to the wrong namesake gets found at all.
     */
    protected function places(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $show = $this->show($request);
        ['sort' => $sort, 'dir' => $dir] = $this->order($request);
        $locale = admin_content_locale();

        return Place::query()
            ->select('m_locations_places.*')
            // Every column here is qualified because sorting by region joins
            // the states table, which has a `country` of its own: unqualified,
            // the filter becomes "ambiguous column" the moment that sort is
            // picked — and only then.
            ->where('m_locations_places.country', 'UA')
            ->when($show === 'unmatched', fn ($query) => $query
                ->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('m_np_place_map')
                    ->whereColumn('m_np_place_map.place_id', 'm_locations_places.id')))
            ->when($show === 'manual', fn ($query) => $query
                ->whereExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('m_np_place_map')
                    ->whereColumn('m_np_place_map.place_id', 'm_locations_places.id')
                    ->where('m_np_place_map.matched_by', 'manual')))
            ->when($search !== '', fn ($query) => $query
                ->whereRaw('m_locations_places.search_text like ?', ['%'.mb_strtolower($search).'%']))
            // Sorted by the name the admin is READING, not by some fixed
            // language: the column shows the content locale, so that is what
            // A→Z has to mean. The coalesce chain mirrors HasTranslations::t()
            // — locale, then the base language, then whatever the row has — or
            // a village named only in Ukrainian would sort as if it had no name
            // while the screen shows one. Cyrillic sorts correctly because the
            // database has a real collation (see CREATE DATABASE in CLAUDE.md).
            ->when($sort === 'place', fn ($query) => $query
                ->orderByRaw("lower(coalesce(m_locations_places.name->>?, m_locations_places.name->>'en', m_locations_places.name->>'uk', m_locations_places.name->>'ru')) {$dir}", [$locale]))
            ->when($sort === 'region', fn ($query) => $query
                ->leftJoin('m_locations_states as np_state', 'np_state.id', '=', 'm_locations_places.state_id')
                ->orderByRaw("lower(coalesce(np_state.name->>?, np_state.name->>'en', np_state.name->>'uk')) {$dir}", [$locale]))
            ->when($sort === 'population', fn ($query) => $query->orderBy('m_locations_places.population', $dir))
            // A stable tiebreaker, or page 2 can repeat a row from page 1.
            ->orderBy('m_locations_places.id')
            ->with('state')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * Mappings for the page's places in one query — the list is 25 rows, and
     * a lookup per row is the N+1 this project keeps a budget test for.
     *
     * @return \Illuminate\Support\Collection<int, PlaceMap>
     */
    protected function mapsFor($places)
    {
        return PlaceMap::with('settlement')
            ->whereIn('place_id', $places->pluck('id'))
            ->get()
            ->keyBy('place_id');
    }
}
