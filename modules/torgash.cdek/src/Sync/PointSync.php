<?php

namespace Torgash\Cdek\Sync;

use DateInterval;
use Illuminate\Support\Facades\DB;
use Throwable;
use Torgash\Cdek\Api\Client;
use Torgash\Cdek\Jobs\RefreshCityPoints;
use Torgash\Cdek\Models\Point;
use Torgash\Cdek\Models\Sync;

/**
 * The pickup points of ONE city, and how they stay current — the same
 * lazy-by-city model as Nova Poshta's branches: the first checkout that names
 * an unknown city pays for one request, everybody after it reads the table
 * until the TTL says otherwise, and a city we already have is served from the
 * table and re-fetched in the background when it goes stale.
 *
 * CDEK also answers for a whole country in one paged listing
 * (`deliverypoints?country_code=RU`, 1 000 a page measured fine), which is
 * what `all()` does for a shop that would rather have everything up front.
 *
 * A failed refresh keeps the stale rows rather than emptying the picker.
 */
class PointSync
{
    /** How long to leave the carrier alone after it refuses a request. */
    public const COOLDOWN = 'PT2M';

    /** How long a queued refresh is given to happen before another is queued. */
    public const QUEUE_WINDOW = 'PT15M';

    public const PAGE = 1000;

    public function __construct(protected Client $client) {}

    /** Makes sure the city's points are in the table, as far as one request allows. */
    public function for(int $cityCode): void
    {
        if (! $this->stale($cityCode)) {
            return;
        }

        if (! Point::where('city_code', $cityCode)->exists()) {
            $this->refresh($cityCode);

            return;
        }

        $this->queue($cityCode);
    }

    /**
     * "This customer has named that city" — get its points ready in the
     * background. Never synchronous: nobody is waiting on this yet.
     */
    public function warm(int $cityCode): void
    {
        if ($cityCode > 0 && $this->stale($cityCode)) {
            $this->queue($cityCode);
        }
    }

    protected function queue(int $cityCode): void
    {
        $key = 'points:'.$cityCode.':queued';

        if (Sync::stale($key, new DateInterval(self::QUEUE_WINDOW))) {
            Sync::mark($key);
            RefreshCityPoints::dispatch($cityCode);
        }
    }

    /**
     * A city we have nothing for and could not ask about — the carrier refused
     * the last attempt and is inside its cooldown. Told apart from "this city
     * has no points", because sending that customer to the next town would be
     * directions built on something we do not know.
     */
    public function pending(int $cityCode): bool
    {
        if (Point::where('city_code', $cityCode)->exists()) {
            return false;
        }

        return ! Sync::stale('points:'.$cityCode.':failed', new DateInterval(self::COOLDOWN));
    }

    protected function stale(int $cityCode): bool
    {
        $hours = max(1, (int) setting('torgash.cdek', 'points_ttl_hours', 24));

        if (! Sync::stale('points:'.$cityCode, new DateInterval('PT'.$hours.'H'))) {
            return false;
        }

        // A quote runs on every keystroke in the town field: after a refusal
        // the city is left alone for a couple of minutes.
        return Sync::stale('points:'.$cityCode.':failed', new DateInterval(self::COOLDOWN));
    }

    /** One city, all its points, one marker. */
    public function refresh(int $cityCode): int
    {
        try {
            $rows = array_map(
                fn (array $row) => $this->row($row, $cityCode),
                array_values(array_filter($this->client->get('deliverypoints', ['city_code' => $cityCode]), 'is_array')),
            );
        } catch (Throwable $e) {
            report($e);
            Sync::mark('points:'.$cityCode.':failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return Point::where('city_code', $cityCode)->count();
        }

        $rows = array_values(array_filter($rows, fn (array $row) => $row['code'] !== ''));
        $startedAt = now()->subSecond();

        DB::transaction(function () use ($cityCode, $rows, $startedAt) {
            $this->store($rows);

            // Points close. A row this pass did not see must leave the picker.
            Point::where('city_code', $cityCode)
                ->where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<', $startedAt))
                ->delete();
        });

        Sync::mark('points:'.$cityCode, ['count' => count($rows)]);
        Sync::where('key', 'points:'.$cityCode.':failed')->delete();

        return count($rows);
    }

    /**
     * Every point of every country this shop pulls, in one paged pass, and a
     * fresh marker for every city that has any.
     *
     * @param  callable(int):void|null  $progress
     * @return array{points: int, cities: int}
     */
    public function all(?callable $progress = null): array
    {
        $startedAt = now()->subSecond();
        $seen = 0;
        $cities = [];
        $buffer = [];

        foreach (CitySync::countries() as $country) {
            foreach ($this->client->pages('deliverypoints', ['country_code' => $country], self::PAGE) as $row) {
                $row = $this->row($row);

                if ($row['code'] === '' || $row['city_code'] <= 0) {
                    continue;
                }

                $buffer[] = $row;
                $cities[$row['city_code']] = ($cities[$row['city_code']] ?? 0) + 1;
                $seen++;

                if (count($buffer) >= 500) {
                    $this->store($buffer);
                    $buffer = [];
                    $progress && $progress($seen);
                }
            }
        }

        $this->store($buffer);
        $progress && $progress($seen);

        // Only after the whole listing came back: a pass that died half way
        // threw before reaching this, and the points it did not get to stay.
        Point::where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<', $startedAt))->delete();

        foreach (array_chunk(array_keys($cities), 1000) as $chunk) {
            $now = now();

            Sync::upsert(
                array_map(fn (int $code) => [
                    'key' => 'points:'.$code,
                    'synced_at' => $now,
                    'meta' => json_encode(['count' => $cities[$code]]),
                ], $chunk),
                ['key'],
                ['synced_at', 'meta'],
            );
        }

        Sync::mark('points', ['points' => $seen, 'cities' => count($cities)]);

        return ['points' => $seen, 'cities' => count($cities)];
    }

    /**
     * One row of `deliverypoints` as our columns.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function row(array $row, ?int $cityCode = null): array
    {
        $location = (array) ($row['location'] ?? []);
        $phones = array_values(array_filter((array) ($row['phones'] ?? []), 'is_array'));

        return [
            'code' => mb_substr(trim((string) ($row['code'] ?? '')), 0, 32),
            'city_code' => $cityCode ?? (int) ($location['city_code'] ?? 0),
            'type' => (string) ($row['type'] ?? 'PVZ'),
            'owner' => ($row['owner_code'] ?? '') ?: null,
            'name' => mb_substr((string) ($row['name'] ?? ''), 0, 255),
            'address' => mb_substr((string) ($location['address'] ?? ''), 0, 255),
            'address_full' => mb_substr((string) ($location['address_full'] ?? ''), 0, 500),
            'address_comment' => ($row['address_comment'] ?? '') !== '' ? mb_substr((string) $row['address_comment'], 0, 1000) : null,
            'postal_code' => ($location['postal_code'] ?? '') ?: null,
            'nearest_metro' => ($row['nearest_metro_station'] ?? '') !== '' ? mb_substr((string) $row['nearest_metro_station'], 0, 120) : null,
            'latitude' => is_numeric($location['latitude'] ?? null) ? (float) $location['latitude'] : null,
            'longitude' => is_numeric($location['longitude'] ?? null) ? (float) $location['longitude'] : null,
            'weight_min' => (float) ($row['weight_min'] ?? 0),
            'weight_max' => (float) ($row['weight_max'] ?? 0),
            'dimensions' => isset($row['dimensions']) && is_array($row['dimensions']) ? json_encode(array_values($row['dimensions'])) : null,
            'work_time' => ($row['work_time'] ?? '') !== '' ? mb_substr((string) $row['work_time'], 0, 255) : null,
            'schedule' => json_encode(array_values(array_filter((array) ($row['work_time_list'] ?? []), 'is_array'))),
            'phone' => ($phones[0]['number'] ?? '') !== '' ? mb_substr((string) $phones[0]['number'], 0, 32) : null,
            // Missing means yes: a point that only accepts parcels says so.
            'is_handout' => (bool) ($row['is_handout'] ?? true),
            'allowed_cod' => (bool) ($row['allowed_cod'] ?? false),
            'have_cashless' => (bool) ($row['have_cashless'] ?? false),
            'is_dressing_room' => (bool) ($row['is_dressing_room'] ?? false),
            'synced_at' => now(),
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    protected function store(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $rows = array_values(array_column($rows, null, 'code'));

        foreach (array_chunk($rows, 500) as $chunk) {
            Point::upsert($chunk, ['code'], array_keys($chunk[0]));
        }
    }
}
