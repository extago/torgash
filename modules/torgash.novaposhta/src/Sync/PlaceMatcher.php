<?php

namespace Torgash\NovaPoshta\Sync;

use Illuminate\Support\Facades\DB;
use Torgash\NovaPoshta\Models\PlaceMap;

/**
 * Ties our places to the carrier's settlements, once, offline.
 *
 * Why it has to be done at all: Nova Poshta's API has no "find the town called
 * X" — branches are asked for by settlement UUID (the carrier's own plug-ins
 * read the ref out of a column their vendor sells pre-filled). So
 * the ref has to be sitting on our row before a customer ever types a town.
 *
 * Why it cannot be done by name: Ukraine has 36 places starting with
 * "Шевченк", 80 called Іванівка, and 3 066 names that repeat at all. Name
 * alone is not an identifier, so the chain is:
 *
 *   1. name + oblast + raion   — unique on both sides, the normal case;
 *   2. name + oblast           — accepted only when there is exactly one;
 *   3. name + oblast, several  — the nearest by coordinates wins, but only if
 *      it is within 30 km AND at least twice as close as the runner-up;
 *   4. name alone in the country, if coordinates agree within 30 km — this is
 *      what catches Kyiv and Sevastopol, whose "oblast" the two sides spell
 *      differently because the city IS the region.
 *
 * Anything left over is not a failure to hide: the place is simply not offered
 * Nova Poshta (exactly what the carrier's own plug-ins do with an unmatched city),
 * and the admin gets a list to bind by hand. A row bound by hand is never
 * overwritten by a later run.
 */
class PlaceMatcher
{
    /** Kilometres beyond which two rows with the same name are two places. */
    public const MAX_KM = 30.0;

    /**
     * Kilometres within which two same-named rows are the SAME place, not two.
     * The carrier's directory really does carry a settlement twice (measured:
     * namesakes 0.0 km apart), and refusing to choose between a row and its own
     * twin helps nobody — the parcel goes to the same village either way.
     */
    public const SAME_PLACE_KM = 1.0;

    /** @var array<string, list<object>> normalised key => settlements */
    protected array $byNameArea = [];

    /** @var array<string, list<object>> normalised key => settlements */
    protected array $byNameAreaRegion = [];

    /** @var array<string, list<object>> normalised name => settlements */
    protected array $byName = [];

    /**
     * @param  callable(int, int):void|null  $progress  (done, matched)
     * @return array{examined: int, matched: int, dropped: int, by: array<string, int>}
     */
    public function run(?callable $progress = null): array
    {
        $this->index();
        $startedAt = now();

        // Bound by hand stays bound by hand: a human looked at that one.
        $manual = PlaceMap::where('matched_by', 'manual')->pluck('place_id')->all();
        $manual = array_flip($manual);

        $examined = 0;
        $matched = 0;
        $by = [];
        $buffer = [];

        DB::table('m_locations_places')
            ->select('id', 'name', 'district', 'state_id', 'latitude', 'longitude', 'postal_from', 'postal_to')
            ->where('country', 'UA')
            ->orderBy('id')
            ->chunk(1000, function ($places) use (&$examined, &$matched, &$by, &$buffer, $manual, $progress) {
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
                        'settlement_ref' => $hit['settlement']->ref,
                        'city_ref' => $hit['settlement']->city_ref,
                        'matched_by' => $hit['matched_by'],
                        'confidence' => $hit['confidence'],
                        'checked_at' => now(),
                    ];

                    if (count($buffer) >= 500) {
                        $this->flush($buffer);
                        $buffer = [];
                    }
                }

                $progress && $progress($examined, $matched);
            });

        $this->flush($buffer);

        // Automatic mappings are derived data, so a re-run REPLACES them: a row
        // this pass did not confirm no longer matches (the carrier dropped the
        // settlement, the registry was re-imported) and must stop offering a
        // branch nobody delivers to. Deleting after the upserts rather than
        // before keeps checkout answering correctly throughout the run.
        $stale = PlaceMap::where('matched_by', '!=', 'manual')
            ->where(fn ($query) => $query->whereNull('checked_at')->orWhere('checked_at', '<', $startedAt))
            ->delete();

        return ['examined' => $examined, 'matched' => $matched, 'dropped' => $stale, 'by' => $by];
    }

    /**
     * Candidates for one place, for the admin's manual picker: same oblast
     * first, then anything with that name, nearest first.
     *
     * @return list<object>
     */
    public function candidatesFor(object $place, string $query = '', int $limit = 20): array
    {
        $needle = Normalizer::name($query !== '' ? $query : ($this->names($place)[0] ?? ''));

        if ($needle === '') {
            return [];
        }

        $rows = DB::table('m_np_settlements')
            ->where('kind', 'settlement')
            ->where(function ($sub) use ($needle) {
                $sub->whereRaw('lower(name_uk) like ?', [$needle.'%'])
                    ->orWhereRaw('lower(name_ru) like ?', [$needle.'%']);
            })
            ->limit(200)
            ->get();

        $area = Normalizer::name($this->areaOf($place));

        return collect($rows)
            // The two facts that actually settle it, carried to the screen: how
            // far this candidate is from the place, and whether its postal index
            // falls in the place's range. A list of eight identical village
            // names is a coin flip; "0.3 km, index matches" is not.
            ->each(function ($row) use ($place) {
                $row->distance_km = $this->distance($place, $row);
                $row->postal_match = $this->byPostal($place, [$row]) !== null;
            })
            ->sortBy(fn ($row) => [
                Normalizer::name($row->area) === $area ? 0 : 1,
                $row->postal_match ? 0 : 1,
                $row->distance_km ?? 9999,
            ])
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return array{settlement: object, matched_by: string, confidence: int}|null */
    protected function match(object $place): ?array
    {
        $area = $this->areaOf($place);
        $district = $this->districtOf($place);

        foreach ($this->names($place) as $name) {
            if ($district !== '') {
                $exact = $this->distinct($this->byNameAreaRegion[Normalizer::keyWithRegion($name, $area, $district)] ?? []);

                if (count($exact) === 1) {
                    return ['settlement' => $exact[0], 'matched_by' => 'name_area_district', 'confidence' => 100];
                }
            }

            $inArea = $this->distinct($this->byNameArea[Normalizer::key($name, $area)] ?? []);

            if (count($inArea) === 1) {
                return ['settlement' => $inArea[0], 'matched_by' => 'name_area', 'confidence' => 90];
            }

            // A postal code is an identifier, not a hint: when exactly one
            // namesake's index falls inside this place's range, that is the
            // one, whatever the raions are called this year.
            if (count($inArea) > 1 && ($byPostal = $this->byPostal($place, $inArea))) {
                return ['settlement' => $byPostal, 'matched_by' => 'postal', 'confidence' => 85];
            }

            if (count($inArea) > 1 && ($nearest = $this->nearest($place, $inArea))) {
                return ['settlement' => $nearest, 'matched_by' => 'coords', 'confidence' => 75];
            }

            // Same name, exactly ONE place with it in the whole country, and
            // the coordinates agree: this is the city whose oblast is itself
            // (Kyiv, Sevastopol), where the two sides disagree about what the
            // region is called. Strictly one candidate — several namesakes that
            // the oblast could not separate must not be separated here, or the
            // ambiguity guard above is bypassed by falling through it.
            $anywhere = $this->distinct($this->byName[Normalizer::name($name)] ?? []);

            if (count($anywhere) === 1 && ($nearest = $this->nearest($place, $anywhere, requireGap: false))) {
                return ['settlement' => $nearest, 'matched_by' => 'coords', 'confidence' => 60];
            }
        }

        return null;
    }

    /**
     * One entry per settlement. "How many candidates are there" is the question
     * every rule above turns on, so a settlement counted twice is not a
     * near-miss — it is a different answer.
     *
     * @param  list<object>  $candidates
     * @return list<object>
     */
    protected function distinct(array $candidates): array
    {
        return collect($candidates)->unique('ref')->values()->all();
    }

    /**
     * The closest of several namesakes — but only when it is unambiguous:
     * within 30 km, and (when asked) at least twice as close as the next one.
     *
     * @param  list<object>  $candidates
     */
    protected function nearest(object $place, array $candidates, bool $requireGap = true): ?object
    {
        $distances = [];

        // By ref, because "the same settlement twice" and "two settlements at
        // the same spot" must not look alike to the gap test below.
        foreach (collect($candidates)->unique('ref') as $candidate) {
            $km = $this->distance($place, $candidate);

            if ($km !== null) {
                $distances[] = [$km, $candidate];
            }
        }

        if ($distances === []) {
            return null;
        }

        usort($distances, fn ($a, $b) => $a[0] <=> $b[0]);

        [$best, $settlement] = $distances[0];

        if ($best > self::MAX_KM) {
            return null;
        }

        // Are all the contenders one village listed several times? Measured
        // between THEM, not by their distance to us: two villages 11 km away on
        // opposite sides are equally far and 22 km apart, which is two places.
        // Checked before the ambiguity test, because a row and its own twin are
        // not a tie to refuse — and because the twin with branches is the one a
        // parcel can actually be addressed to.
        $rows = array_column($distances, 1);
        $together = array_filter(
            $rows,
            fn (object $row) => ($this->distance($settlement, $row) ?? INF) <= self::SAME_PLACE_KM,
        );

        if (count($together) === count($rows)) {
            return $this->withBranches(array_values($together));
        }

        if ($requireGap && isset($distances[1]) && $distances[1][0] < $best * 2) {
            return null;
        }

        return $settlement;
    }

    /**
     * The one whose postal index this place's range covers — and only when
     * exactly one does. Two candidates inside the range is not an answer.
     *
     * @param  list<object>  $candidates
     */
    protected function byPostal(object $place, array $candidates): ?object
    {
        $from = (string) ($place->postal_from ?? '');

        if ($from === '') {
            return null;
        }

        $to = (string) ($place->postal_to ?: $from);

        $hits = array_values(array_filter($candidates, function (object $candidate) use ($from, $to) {
            $index = (string) ($candidate->index1 ?? '');

            return $index !== '' && $index >= $from && $index <= $to;
        }));

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * Among rows that are the same place, the useful one: a settlement with
     * branches can be delivered to; its twin without any cannot.
     *
     * @param  list<object>  $candidates
     */
    protected function withBranches(array $candidates): ?object
    {
        foreach ($candidates as $candidate) {
            if ($candidate->has_warehouse) {
                return $candidate;
            }
        }

        // None has branches: any of them is as good (or as useless) as the
        // others, so pick deterministically rather than by chunk order.
        usort($candidates, fn ($a, $b) => strcmp($a->ref, $b->ref));

        return $candidates[0] ?? null;
    }

    /**
     * Kilometres between two rows, or null when either has no coordinates.
     * Takes any two rows with latitude/longitude — ours against the carrier's,
     * or two of the carrier's against each other.
     */
    protected function distance(object $place, object $settlement): ?float
    {
        if ($place->latitude === null || $settlement->latitude === null) {
            return null;
        }

        // Equirectangular approximation: at these distances the error is
        // metres, and this runs 30 000 × a few times.
        $lat1 = deg2rad((float) $place->latitude);
        $lat2 = deg2rad((float) $settlement->latitude);
        $dLon = deg2rad((float) $settlement->longitude - (float) $place->longitude);
        $x = $dLon * cos(($lat1 + $lat2) / 2);
        $y = $lat2 - $lat1;

        return sqrt($x * $x + $y * $y) * 6371.0;
    }

    /** Every spelling of a place worth trying, in the order most likely to hit. */
    protected function names(object $place): array
    {
        $name = $this->translations($place->name);

        return array_values(array_unique(array_filter([$name['uk'] ?? null, $name['ru'] ?? null])));
    }

    /**
     * The run() pass reads raw query-builder rows (JSON strings); the admin's
     * picker hands in an Eloquent Place, whose jsonb columns are already cast
     * to arrays. Both arrive here, so both are accepted — casting the array to
     * a string is the "Array to string conversion" this exists to prevent.
     *
     * @return array<string, string>
     */
    protected function translations(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return json_decode((string) $value, true) ?: [];
    }

    protected function areaOf(object $place): string
    {
        return $this->states()[$place->state_id] ?? '';
    }

    protected function districtOf(object $place): string
    {
        $district = $this->translations($place->district);

        return (string) ($district['uk'] ?? $district['und'] ?? $district['ru'] ?? '');
    }

    /** @var array<int, string>|null */
    protected ?array $states = null;

    /** @return array<int, string> */
    protected function states(): array
    {
        return $this->states ??= DB::table('m_locations_states')
            ->where('country', 'UA')
            ->pluck('name', 'id')
            ->map(fn ($name) => $this->translations($name)['uk'] ?? '')
            ->all();
    }

    /**
     * The carrier's directory, indexed three ways, once.
     *
     * Deduplicated by the NORMALISED name, not the raw one. The two spellings a
     * settlement carries — "П'ятихатки" and "Пятихатки" — are different strings
     * that mean the same key, so indexing both filed the same settlement twice
     * under it. Every rule downstream then read that as two candidates: the
     * "exactly one" test never fired, and the coordinate tie-break rejected the
     * match for not being twice as close as ITSELF. Пятихатки sat 0.3 km from
     * its obvious counterpart and stayed unmatched for exactly this reason.
     */
    protected function index(): void
    {
        $this->byNameArea = $this->byNameAreaRegion = $this->byName = [];

        DB::table('m_np_settlements')
            ->select('ref', 'name_uk', 'name_ru', 'area', 'region', 'city_ref', 'latitude', 'longitude', 'index1', 'has_warehouse')
            ->where('kind', 'settlement')
            ->orderBy('ref')
            ->chunk(2000, function ($rows) {
                foreach ($rows as $row) {
                    $names = array_unique(array_map(
                        fn (string $name) => Normalizer::name($name),
                        array_filter([$row->name_uk, $row->name_ru]),
                    ));

                    foreach ($names as $name) {
                        $this->byNameArea[$name.'|'.Normalizer::name($row->area)][] = $row;
                        $this->byNameAreaRegion[$name.'|'.Normalizer::name($row->area).'|'.Normalizer::name((string) $row->region)][] = $row;
                        $this->byName[$name][] = $row;
                    }
                }
            });
    }

    /** @param  list<array<string, mixed>>  $rows */
    protected function flush(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table('m_np_place_map')->upsert(
            $rows,
            ['place_id'],
            ['settlement_ref', 'city_ref', 'matched_by', 'confidence', 'checked_at'],
        );
    }
}
