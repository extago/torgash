<?php

namespace Torgash\NovaPoshta\Sync;

use Illuminate\Support\Facades\DB;
use Torgash\NovaPoshta\Api\Client;
use Torgash\NovaPoshta\Models\Sync;

/**
 * Pulls Nova Poshta's own directory into our copy of it.
 *
 * Two lists, because the carrier keeps two (verified against the live API):
 *
 *   Address/getSettlements — 26 770 rows: every settlement it knows, with
 *       coordinates, oblast, raion and postal indexes. This is the list a
 *       branch or locker delivery is addressed by.
 *   Address/getCities      — 11 156 rows: the subset that is a "city" for
 *       courier delivery. No coordinates, and its Description carries the
 *       raion and oblast in brackets ("Абазівка (Полтавський р-н, …)").
 *
 * A settlement is linked to its city by name + oblast, which is the only join
 * the API offers — there is no field saying "this village's parcels leave from
 * that town". A village with no city of its own gets no city_ref and simply is
 * not offered courier delivery, which is honest rather than wrong.
 *
 * The whole thing is ~80 requests at 500 rows a page (1000 works, 2000 is an
 * HTTP 500 — measured), so it is a job, not a request.
 */
class DirectorySync
{
    public const PAGE = 500;

    public function __construct(protected Client $client) {}

    /**
     * @param  callable(int):void|null  $progress  called with the running row count
     * @return array{settlements: int, cities: int, linked: int}
     */
    public function run(?callable $progress = null): array
    {
        $seen = 0;
        $cityRefs = [];

        // Cities first: the settlements pass needs their refs to link against.
        $cities = $this->store('city', $this->client->pages('Address', 'getCities', [], self::PAGE), function (array $row) use (&$cityRefs) {
            $name = $this->stripBrackets($row['Description'] ?? '');
            $area = trim((string) ($row['AreaDescription'] ?? ''));
            $cityRefs[Normalizer::key($name, $area)] = $row['Ref'];

            return [
                'ref' => $row['Ref'],
                'kind' => 'city',
                'name_uk' => $name,
                'name_ru' => $this->stripBrackets($row['DescriptionRu'] ?? ''),
                'area' => $area,
                'region' => null,
                'settlement_type' => $row['SettlementTypeDescription'] ?? null,
                'latitude' => null,
                'longitude' => null,
                'has_warehouse' => true,
                'city_ref' => $row['Ref'],
                'index1' => null,
                'index2' => null,
                'synced_at' => now(),
            ];
        }, $progress, $seen);

        $linked = 0;
        $settlements = $this->store('settlement', $this->client->pages('Address', 'getSettlements', [], self::PAGE), function (array $row) use ($cityRefs, &$linked) {
            $name = trim((string) ($row['Description'] ?? ''));
            $area = trim((string) ($row['AreaDescription'] ?? ''));
            $cityRef = $cityRefs[Normalizer::key($name, $area)] ?? null;
            $linked += $cityRef ? 1 : 0;

            return [
                'ref' => $row['Ref'],
                'kind' => 'settlement',
                'name_uk' => $name,
                'name_ru' => trim((string) ($row['DescriptionRu'] ?? '')),
                'area' => $area,
                // "Полтавський" — the raion, without the word. Ours says
                // "Полтавський район", so the matcher strips both ends.
                'region' => ($row['RegionsDescription'] ?? '') ?: null,
                'settlement_type' => $row['SettlementTypeDescription'] ?? null,
                'latitude' => $this->number($row['Latitude'] ?? null),
                'longitude' => $this->number($row['Longitude'] ?? null),
                'has_warehouse' => ($row['Warehouse'] ?? '0') === '1',
                'city_ref' => $cityRef,
                'index1' => ($row['Index1'] ?? '') ?: null,
                'index2' => ($row['Index2'] ?? '') ?: null,
                'synced_at' => now(),
            ];
        }, $progress, $seen);

        Sync::mark('settlements', ['settlements' => $settlements, 'cities' => $cities, 'linked' => $linked]);

        return ['settlements' => $settlements, 'cities' => $cities, 'linked' => $linked];
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): array<string, mixed>  $map
     * @param  callable(int):void|null  $progress
     */
    protected function store(string $kind, iterable $rows, callable $map, ?callable $progress, int &$seen): int
    {
        $buffer = [];
        $stored = 0;

        foreach ($rows as $row) {
            if (empty($row['Ref'])) {
                continue;
            }

            $buffer[] = $map($row);
            $stored++;
            $seen++;

            if (count($buffer) >= self::PAGE) {
                $this->flush($buffer);
                $buffer = [];
                $progress && $progress($seen);
            }
        }

        $this->flush($buffer);
        $progress && $progress($seen);

        // A settlement that left the carrier's directory must leave ours, or a
        // stale mapping keeps offering a branch nobody delivers to any more.
        DB::table('m_np_settlements')
            ->where('kind', $kind)
            ->where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<', now()->subMinutes(30)))
            ->delete();

        return $stored;
    }

    /** @param  list<array<string, mixed>>  $rows */
    protected function flush(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table('m_np_settlements')->upsert($rows, ['ref'], array_keys($rows[0]));
    }

    /** "Абазівка (Полтавський р-н, Полтавська обл)" → "Абазівка". */
    protected function stripBrackets(string $value): string
    {
        return trim(preg_replace('/\s*\(.*$/u', '', $value) ?? $value);
    }

    protected function number(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value !== 0.0 ? (float) $value : null;
    }
}
