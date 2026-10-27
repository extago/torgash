<?php

namespace Torgash\Cdek\Shipping;

use Illuminate\Support\Facades\DB;

/**
 * "There is no CDEK point in your village — the nearest are 14 km away, in
 * these towns."
 *
 * Simpler than Nova Poshta's answer to the same question, because CDEK's
 * points carry their own coordinates: the nearest POINTS are found directly,
 * grouped by the city they are in, and each city is offered as one of our
 * places through the mapping — a city with points but no place of ours is
 * somewhere the customer could not choose anyway, so it is left out.
 *
 * Only as complete as the points table: after "Fetch all pickup points" every
 * town is covered, before it only the towns somebody has already asked for.
 * A bounding box first, so the trigonometry runs over a few hundred rows.
 */
class NearestTowns
{
    /** Beyond this a "nearest point" is not an answer, it is a different trip. */
    public const RADIUS_KM = 60.0;

    /**
     * @return list<array{place_id: int, name: string, km: float, kinds: list<string>}>
     */
    public function around(int $placeId, int $limit = 8): array
    {
        $origin = DB::table('m_locations_places')->where('id', $placeId)->first(['latitude', 'longitude']);

        if (! $origin || $origin->latitude === null || $origin->longitude === null) {
            return [];
        }

        $lat = (float) $origin->latitude;
        $lon = (float) $origin->longitude;
        $latSpan = self::RADIUS_KM / 111.0;
        $lonSpan = self::RADIUS_KM / max(1.0, 111.0 * cos(deg2rad($lat)));

        $near = DB::table('m_cdek_points as pt')
            ->where('pt.is_handout', true)
            ->whereBetween('pt.latitude', [$lat - $latSpan, $lat + $latSpan])
            ->whereBetween('pt.longitude', [$lon - $lonSpan, $lon + $lonSpan])
            ->selectRaw(
                'pt.city_code, pt.type, '
                // Clamped: acos of a hair over 1 is NaN, which sorts nowhere.
                .'6371 * acos(least(1, greatest(-1, '
                .'cos(radians(?)) * cos(radians(pt.latitude)) * cos(radians(pt.longitude) - radians(?)) '
                .'+ sin(radians(?)) * sin(radians(pt.latitude))))) as km',
                [$lat, $lon, $lat],
            );

        $cities = DB::query()
            ->fromSub($near, 'n')
            ->where('km', '<=', self::RADIUS_KM)
            ->groupBy('city_code')
            ->selectRaw('city_code, min(km) as km, array_to_string(array_agg(distinct type), \',\') as kinds')
            ->orderBy('km')
            ->limit($limit * 3)
            ->get();

        if ($cities->isEmpty()) {
            return [];
        }

        // One of our places per city, the most populous: a CDEK city may be
        // matched by several of our rows (a town and its namesake district).
        $places = DB::table('m_cdek_place_map as m')
            ->join('m_locations_places as p', 'p.id', '=', 'm.place_id')
            ->whereIn('m.city_code', $cities->pluck('city_code'))
            ->where('p.id', '!=', $placeId)
            ->orderByDesc('p.population')
            ->get(['m.city_code', 'p.id', 'p.name'])
            ->unique('city_code')
            ->keyBy('city_code');

        return $cities
            ->filter(fn ($city) => $places->has($city->city_code))
            ->take($limit)
            ->map(fn ($city) => [
                'place_id' => (int) $places[$city->city_code]->id,
                'name' => $this->name($places[$city->city_code]->name),
                'km' => round((float) $city->km, 1),
                'kinds' => array_values(array_filter(explode(',', (string) $city->kinds))),
            ])
            ->values()
            ->all();
    }

    /** The town's name in the visitor's own language. */
    protected function name(?string $json): string
    {
        $names = json_decode((string) $json, true) ?: [];

        return (string) ($names[app()->getLocale()] ?? $names['ru'] ?? $names['en'] ?? reset($names) ?: '');
    }
}
