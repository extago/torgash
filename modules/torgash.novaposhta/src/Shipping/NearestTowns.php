<?php

namespace Torgash\NovaPoshta\Shipping;

use Illuminate\Support\Facades\DB;
use Torgash\NovaPoshta\Models\Warehouse;

/**
 * "There is no branch in your village — the nearest one is eighteen kilometres
 * away, and here is what it has."
 *
 * A village with no branch is the one destination a Ukrainian shop meets every
 * week and no shop answers well: the carrier simply disappears from the list,
 * and the customer is left to guess whether the shop delivers at all. The
 * carrier's own directory already knows which settlements have branches
 * (`has_warehouse`), and our places carry coordinates for the whole country —
 * so the answer is a query, not a service to buy.
 *
 * Distances are the great-circle ones: the carrier does not publish road
 * distances and a straight line is the honest approximation for "how far is
 * it", which is what the customer is deciding on. A bounding box comes first
 * so the trigonometry runs over a few hundred rows rather than 174 000.
 *
 * WHAT is there — a branch, a locker, a cargo department — is only known for a
 * town whose branches have been fetched, so it is shown where it is known and
 * left out where it is not, never guessed at. Looking at the answer warms the
 * nearest few in the background, which is what makes the second look richer
 * than the first.
 */
class NearestTowns
{
    /** Beyond this a "nearest branch" is not an answer, it is a different trip. */
    public const RADIUS_KM = 60.0;

    /** How many of the nearest towns are worth fetching branches for on sight. */
    public const WARM = 3;

    /**
     * The nearest places that have Nova Poshta branches, closest first.
     *
     * @return list<array{place_id: int, name: string, km: float, kinds: list<string>}>
     */
    public function around(int $placeId, int $limit = 10): array
    {
        $origin = DB::table('m_locations_places')
            ->where("id", $placeId)
            ->first(['latitude', 'longitude']);

        if (! $origin || $origin->latitude === null || $origin->longitude === null) {
            return [];
        }

        $lat = (float) $origin->latitude;
        $lon = (float) $origin->longitude;

        // One degree of latitude is 111 km everywhere; a degree of longitude is
        // that times the cosine of the latitude, which at Ukraine's latitudes
        // is about 71 km. The box is deliberately generous — it only has to be
        // cheap, the distance below is what decides.
        $latSpan = self::RADIUS_KM / 111.0;
        $lonSpan = self::RADIUS_KM / max(1.0, 111.0 * cos(deg2rad($lat)));

        // The distance is worked out once, in a subquery, and filtered outside
        // it: repeating the expression in a HAVING is what Postgres refuses
        // without a GROUP BY, and repeating it at all is two places for one
        // formula to be edited.
        $near = DB::table('m_locations_places as p')
            ->join('m_np_place_map as m', 'm.place_id', '=', 'p.id')
            ->join('m_np_settlements as s', 's.ref', '=', 'm.settlement_ref')
            ->where('s.has_warehouse', true)
            ->where('p.id', '!=', $placeId)
            ->whereBetween('p.latitude', [$lat - $latSpan, $lat + $latSpan])
            ->whereBetween('p.longitude', [$lon - $lonSpan, $lon + $lonSpan])
            ->selectRaw(
                'p.id, p.name, m.settlement_ref, '
                // Clamped: floating point can hand acos() a number a hair over
                // 1 for a point compared with itself, and acos(1.0000001) is
                // NaN, which sorts nowhere.
                .'6371 * acos(least(1, greatest(-1, '
                .'cos(radians(?)) * cos(radians(p.latitude)) * cos(radians(p.longitude) - radians(?)) '
                .'+ sin(radians(?)) * sin(radians(p.latitude))))) as km',
                [$lat, $lon, $lat],
            );

        $rows = DB::query()
            ->fromSub($near, 'n')
            ->where('km', '<=', self::RADIUS_KM)
            ->orderBy('km')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $kinds = $this->kinds($rows->pluck('settlement_ref')->all());

        return $rows->map(fn ($row) => [
            'place_id' => (int) $row->id,
            'name' => $this->name($row->name),
            'km' => round((float) $row->km, 1),
            'kinds' => $kinds[$row->settlement_ref] ?? [],
        ])->values()->all();
    }

    /**
     * What each of those towns has, for the towns whose branches are in the
     * table. One query for the lot.
     *
     * @param  list<string>  $refs
     * @return array<string, list<string>>
     */
    protected function kinds(array $refs): array
    {
        return Warehouse::query()
            ->whereIn('settlement_ref', $refs)
            ->where('status', 'Working')
            ->where('deny_to_select', false)
            ->whereRaw("coalesce(np_category, '') not in ('DropOff', 'Fulfillment')")
            ->select('settlement_ref', 'category')
            ->distinct()
            ->get()
            ->groupBy('settlement_ref')
            ->map(fn ($rows) => $rows->pluck('category')->unique()->values()->all())
            ->all();
    }

    /** The town's name in the visitor's own language. */
    protected function name(?string $json): string
    {
        $names = json_decode((string) $json, true) ?: [];

        return (string) ($names[app()->getLocale()] ?? $names['uk'] ?? $names['en'] ?? reset($names) ?: '');
    }
}
