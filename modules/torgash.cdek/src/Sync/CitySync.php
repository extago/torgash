<?php

namespace Torgash\Cdek\Sync;

use Illuminate\Support\Facades\DB;
use Torgash\Cdek\Api\Client;
use Torgash\Cdek\Models\Sync;

/**
 * Pulls CDEK's city directory into our copy of it.
 *
 * One list, `location/cities`, per country. Measured on the test server:
 * Russia is ~140 000 rows, and 5 000 a page is accepted, so the whole country
 * is ~28 requests — half a minute, which makes it a job rather than a request.
 * Rows with a negative code exist (Иннополис, -1) and are skipped: a code is
 * what every other call is addressed by, and that one addresses nothing.
 */
class CitySync
{
    public const PAGE = 5000;

    public function __construct(protected Client $client) {}

    /** @return list<string> the countries this shop pulls, from the module setting */
    public static function countries(): array
    {
        $codes = preg_split('/[\s,;]+/', strtoupper((string) setting('torgash.cdek', 'countries', 'RU')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($codes, fn ($code) => preg_match('/^[A-Z]{2}$/', $code))));
    }

    /**
     * @param  callable(int):void|null  $progress  called with the running row count
     * @return array{cities: int, countries: list<string>}
     */
    public function run(?callable $progress = null): array
    {
        $startedAt = now()->subSecond();
        $seen = 0;
        $buffer = [];
        $countries = self::countries();

        foreach ($countries as $country) {
            foreach ($this->client->pages('location/cities', ['country_codes' => $country], self::PAGE) as $row) {
                $code = (int) ($row['code'] ?? 0);

                if ($code <= 0) {
                    continue;
                }

                $buffer[] = [
                    'code' => $code,
                    'uuid' => ($row['city_uuid'] ?? '') ?: null,
                    'country' => (string) ($row['country_code'] ?? $country),
                    'name' => mb_substr(trim((string) ($row['city'] ?? '')), 0, 190),
                    'region' => mb_substr(trim((string) ($row['region'] ?? '')), 0, 190),
                    'region_code' => isset($row['region_code']) ? (int) $row['region_code'] : null,
                    'sub_region' => ($row['sub_region'] ?? '') !== '' ? mb_substr((string) $row['sub_region'], 0, 190) : null,
                    'latitude' => $this->number($row['latitude'] ?? null),
                    'longitude' => $this->number($row['longitude'] ?? null),
                    'time_zone' => ($row['time_zone'] ?? '') ?: null,
                    'synced_at' => now(),
                ];
                $seen++;

                if (count($buffer) >= 1000) {
                    $this->flush($buffer);
                    $buffer = [];
                    $progress && $progress($seen);
                }
            }
        }

        $this->flush($buffer);
        $progress && $progress($seen);

        // A city that left the carrier's directory must leave ours, or a
        // stale mapping keeps offering delivery nobody makes any more. Only
        // within the countries just pulled — a country taken out of the
        // setting keeps its rows until somebody decides otherwise.
        DB::table('m_cdek_cities')
            ->whereIn('country', $countries)
            ->where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<', $startedAt))
            ->delete();

        Sync::mark('cities', ['cities' => $seen, 'countries' => $countries]);

        return ['cities' => $seen, 'countries' => $countries];
    }

    /** @param  list<array<string, mixed>>  $rows */
    protected function flush(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        // The same code twice in one batch is an upsert Postgres refuses
        // ("cannot affect row a second time"); the later row wins.
        $rows = array_values(array_column($rows, null, 'code'));

        DB::table('m_cdek_cities')->upsert($rows, ['code'], array_keys($rows[0]));
    }

    protected function number(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value !== 0.0 ? (float) $value : null;
    }
}
