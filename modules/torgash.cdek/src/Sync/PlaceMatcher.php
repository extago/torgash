<?php

namespace Torgash\Cdek\Sync;

use Illuminate\Support\Facades\DB;
use Torgash\Cdek\Models\PlaceMap;

/**
 * Ties our places to CDEK's cities, once, offline.
 *
 * Why at all: CDEK addresses everything by its own city code — the points of a
 * city, a price from one city to another — so the code has to be on our row
 * before a customer types a town. Its API can look a name up, but a lookup per
 * keystroke in the town field is a rate limit and a guess per checkout; once
 * offline is neither.
 *
 * Why not by name: Russia has dozens of every village name. CDEK gives each
 * city its region and its district (`sub_region`), and coordinates — but the
 * coordinates of its OLDEST rows cannot be trusted (measured on the test
 * directory: Иркутск sits 500 km from Irkutsk, and the Moscow-oblast Чехов is
 * placed on Sakhalin, next to the Sakhalin one). The big towns are exactly
 * those old rows, so the administrative names come first and coordinates
 * only break the ties they leave:
 *
 *   1. name + region + district, exactly one;
 *   2. name + region, exactly one — and either we have only one place of that
 *      name in the region too, or the coordinates agree (otherwise the one
 *      CDEK row would be handed to every namesake of ours);
 *   3. several in the region: the nearest, within 30 km and at least twice as
 *      close as the next (or all of them one place listed twice);
 *   4. the same, across the country — the region names may simply differ;
 *   5. the name is unique in the whole directory, and nothing it knows
 *      contradicts it (another region, or 30 km away).
 *
 * What is left over is simply not offered CDEK and is listed for the admin to
 * bind by hand. A row bound by hand is never overwritten by a later run.
 */
class PlaceMatcher
{
    /** Kilometres beyond which two rows with the same name are two places. */
    public const MAX_KM = 30.0;

    /** Kilometres within which two same-named rows are the SAME place listed twice. */
    public const SAME_PLACE_KM = 1.0;

    /** @var array<string, list<array{0: int, 1: ?float, 2: ?float, 3: string, 4: string}>> name => [code, lat, lon, region, district] */
    protected array $byName = [];

    /** @var array<string, int> "name|region" => how many of OUR places carry it */
    protected array $ours = [];

    /** @var array<int, string>|null */
    protected ?array $states = null;

    /**
     * @param  callable(int, int):void|null  $progress  (done, matched)
     * @return array{examined: int, matched: int, dropped: int, by: array<string, int>}
     */
    public function run(?callable $progress = null): array
    {
        $countries = CitySync::countries();
        $this->index($countries);
        $startedAt = now()->subSecond();

        // Bound by hand stays bound by hand: a human looked at that one.
        $manual = array_flip(PlaceMap::where('matched_by', 'manual')->pluck('place_id')->all());

        $examined = 0;
        $matched = 0;
        $by = [];
        $buffer = [];

        DB::table('m_locations_places')
            ->select('id', 'name', 'district', 'state_id', 'latitude', 'longitude')
            ->whereIn('country', $countries)
            ->orderBy('id')
            ->chunk(2000, function ($places) use (&$examined, &$matched, &$by, &$buffer, $manual, $progress) {
                foreach ($places as $place) {
                    $examined++;

                    if (isset($manual[$place->id])) {
                        continue;
                    }

                    $hit = $this->match($place);

                    if ($hit === null) {
                        continue;
                    }

                    $matched++;
                    $by[$hit['matched_by']] = ($by[$hit['matched_by']] ?? 0) + 1;
                    $buffer[] = [
                        'place_id' => $place->id,
                        'city_code' => $hit['code'],
                        'matched_by' => $hit['matched_by'],
                        'confidence' => $hit['confidence'],
                        'checked_at' => now(),
                    ];

                    if (count($buffer) >= 1000) {
                        $this->flush($buffer);
                        $buffer = [];
                    }
                }

                $progress && $progress($examined, $matched);
            });

        $this->flush($buffer);

        // Automatic mappings are derived data, so a re-run REPLACES them —
        // after the upserts rather than before, so checkout keeps answering
        // throughout the run.
        $dropped = PlaceMap::where('matched_by', '!=', 'manual')
            ->where(fn ($query) => $query->whereNull('checked_at')->orWhere('checked_at', '<', $startedAt))
            ->delete();

        $this->byName = $this->ours = [];

        return ['examined' => $examined, 'matched' => $matched, 'dropped' => $dropped, 'by' => $by];
    }

    /**
     * Candidates for one place, for the admin's manual picker: by the typed
     * text (or the place's own name), same region first, then nearest.
     *
     * @return list<object>
     */
    public function candidatesFor(object $place, string $query = '', int $limit = 20): array
    {
        $needle = $query !== '' ? $query : ($this->names($place)[0] ?? '');
        $needle = mb_strtolower(str_replace(['ё', 'Ё'], ['е', 'Е'], trim($needle)));

        if ($needle === '') {
            return [];
        }

        $rows = DB::table('m_cdek_cities')
            ->whereIn('country', CitySync::countries())
            ->whereRaw("replace(lower(name), 'ё', 'е') like ?", [$needle.'%'])
            ->limit(200)
            ->get();

        $region = $this->regionOf($place);

        return collect($rows)
            ->each(fn ($row) => $row->distance_km = $this->distance($place, $row))
            ->sortBy(fn ($row) => [
                $region !== '' && Normalizer::name((string) $row->region) === $region ? 0 : 1,
                $row->distance_km ?? 99999,
                $row->code,
            ])
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return array{code: int, matched_by: string, confidence: int}|null */
    protected function match(object $place): ?array
    {
        $region = $this->regionOf($place);
        $district = $this->districtOf($place);

        foreach ($this->names($place) as $name) {
            $key = Normalizer::name($name);
            $candidates = $this->byName[$key] ?? [];

            if ($candidates === []) {
                continue;
            }

            $inRegion = $region === ''
                ? []
                : array_values(array_filter($candidates, fn (array $candidate) => $candidate[3] === $region));

            if ($district !== '') {
                $inDistrict = array_values(array_filter($inRegion, fn (array $candidate) => $candidate[4] === $district));

                if (count($inDistrict) === 1) {
                    return ['code' => $inDistrict[0][0], 'matched_by' => 'name_district', 'confidence' => 95];
                }
            }

            if (count($inRegion) === 1
                && (($this->ours[$key.'|'.$region] ?? 0) <= 1 || ($this->kmTo($place, $inRegion[0]) ?? INF) <= self::MAX_KM)) {
                return ['code' => $inRegion[0][0], 'matched_by' => 'name_region', 'confidence' => 85];
            }

            if (count($inRegion) > 1 && ($code = $this->nearest($place, $inRegion)) !== null) {
                return ['code' => $code, 'matched_by' => 'coords', 'confidence' => 80];
            }

            if (($code = $this->nearest($place, $candidates)) !== null) {
                return ['code' => $code, 'matched_by' => 'coords', 'confidence' => 75];
            }

            if (count($candidates) === 1
                && ($region === '' || $candidates[0][3] === '' || $candidates[0][3] === $region)
                && ($this->kmTo($place, $candidates[0]) ?? 0) <= self::MAX_KM) {
                return ['code' => $candidates[0][0], 'matched_by' => 'name', 'confidence' => 60];
            }
        }

        return null;
    }

    /** @param  array{0: int, 1: ?float, 2: ?float}  $candidate */
    protected function kmTo(object $place, array $candidate): ?float
    {
        return $this->distance($place, (object) ['latitude' => $candidate[1], 'longitude' => $candidate[2]]);
    }

    /**
     * The closest namesake, when it is unambiguous: within 30 km, and either
     * twice as close as the runner-up or one place with all the others.
     *
     * @param  list<array{0: int, 1: ?float, 2: ?float, 3: string}>  $candidates
     */
    protected function nearest(object $place, array $candidates): ?int
    {
        $distances = [];

        foreach ($candidates as $candidate) {
            $km = $this->distance($place, (object) ['latitude' => $candidate[1], 'longitude' => $candidate[2]]);

            if ($km !== null) {
                $distances[] = [$km, $candidate];
            }
        }

        if ($distances === []) {
            return null;
        }

        usort($distances, fn ($a, $b) => [$a[0], $a[1][0]] <=> [$b[0], $b[1][0]]);
        [$best, $winner] = $distances[0];

        if ($best > self::MAX_KM) {
            return null;
        }

        $runnerUp = $distances[1] ?? null;

        if ($runnerUp === null || $runnerUp[0] >= max($best * 2, 0.001)) {
            return $winner[0];
        }

        // Measured between the contenders themselves, not by their distance
        // to us: two villages 11 km away on opposite sides are equally far and
        // 22 km apart, which is two places.
        $point = (object) ['latitude' => $winner[1], 'longitude' => $winner[2]];

        foreach (array_slice($distances, 1) as [$km, $other]) {
            if ($km > self::MAX_KM) {
                break;
            }

            $apart = $this->distance($point, (object) ['latitude' => $other[1], 'longitude' => $other[2]]);

            if ($apart === null || $apart > self::SAME_PLACE_KM) {
                return null;
            }
        }

        return $winner[0];
    }

    /** Kilometres between two rows with latitude/longitude, or null when either has none. */
    protected function distance(object $a, object $b): ?float
    {
        if ($a->latitude === null || $b->latitude === null || $a->longitude === null || $b->longitude === null) {
            return null;
        }

        // Equirectangular: at these distances the error is metres, and this
        // runs a few hundred thousand times.
        $lat1 = deg2rad((float) $a->latitude);
        $lat2 = deg2rad((float) $b->latitude);
        $x = deg2rad((float) $b->longitude - (float) $a->longitude) * cos(($lat1 + $lat2) / 2);
        $y = $lat2 - $lat1;

        return sqrt($x * $x + $y * $y) * 6371.0;
    }

    /**
     * Every spelling worth trying. CDEK names its cities in Russian, so the
     * Russian name comes first and the others only help where it is missing.
     *
     * @return list<string>
     */
    protected function names(object $place): array
    {
        $name = $this->translations($place->name);

        return array_values(array_unique(array_filter([$name['ru'] ?? null, $name['und'] ?? null, $name['en'] ?? null])));
    }

    /**
     * The run() pass reads raw rows (JSON strings), the admin's picker hands in
     * an Eloquent Place with the jsonb already cast. Both arrive here.
     *
     * @return array<string, string>
     */
    protected function translations(mixed $value): array
    {
        return is_array($value) ? $value : (json_decode((string) $value, true) ?: []);
    }

    protected function regionOf(object $place): string
    {
        return $this->states()[$place->state_id ?? 0] ?? '';
    }

    /**
     * "Одинцовский городской округ" on both sides, reduced to "одинцовский" —
     * the one thing that separates two namesakes in one region when the
     * carrier's coordinates for one of them are wrong.
     */
    protected function districtOf(object $place): string
    {
        $district = $this->translations($place->district ?? null);

        return Normalizer::name((string) ($district['ru'] ?? $district['und'] ?? ''));
    }

    /** @return array<int, string> state id => normalised Russian name */
    protected function states(): array
    {
        return $this->states ??= DB::table('m_locations_states')
            ->whereIn('country', CitySync::countries())
            ->pluck('name', 'id')
            ->map(function ($name) {
                $names = $this->translations($name);

                return Normalizer::name((string) ($names['ru'] ?? $names['und'] ?? $names['en'] ?? ''));
            })
            ->all();
    }

    /**
     * The carrier's directory, indexed by normalised name, as small arrays —
     * 140 000 objects would be four times the memory for the same answer.
     *
     * @param  list<string>  $countries
     */
    protected function index(array $countries): void
    {
        $this->byName = $this->ours = [];

        // How many of OUR places share a name within a region — rule 2 hands
        // CDEK's one row to ours only when ours is one too.
        DB::table('m_locations_places')
            ->whereIn('country', $countries)
            ->selectRaw("coalesce(name->>'ru', name->>'und', name->>'en') as name, state_id, count(*) as total")
            ->groupByRaw("coalesce(name->>'ru', name->>'und', name->>'en'), state_id")
            ->orderBy('state_id')
            ->cursor()
            ->each(function ($row) {
                $key = Normalizer::name((string) $row->name).'|'.($this->states()[$row->state_id ?? 0] ?? '');
                $this->ours[$key] = ($this->ours[$key] ?? 0) + (int) $row->total;
            });

        DB::table('m_cdek_cities')
            ->select('code', 'name', 'region', 'sub_region', 'latitude', 'longitude')
            ->whereIn('country', $countries)
            ->orderBy('code')
            ->chunk(5000, function ($rows) {
                foreach ($rows as $row) {
                    $name = Normalizer::name((string) $row->name);

                    if ($name === '') {
                        continue;
                    }

                    $this->byName[$name][] = [
                        (int) $row->code,
                        $row->latitude === null ? null : (float) $row->latitude,
                        $row->longitude === null ? null : (float) $row->longitude,
                        Normalizer::name((string) $row->region),
                        Normalizer::name((string) $row->sub_region),
                    ];
                }
            });
    }

    /** @param  list<array<string, mixed>>  $rows */
    protected function flush(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table('m_cdek_place_map')->upsert($rows, ['place_id'], ['city_code', 'matched_by', 'confidence', 'checked_at']);
    }
}
