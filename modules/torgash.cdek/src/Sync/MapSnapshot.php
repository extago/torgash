<?php

namespace Torgash\Cdek\Sync;

use Illuminate\Support\Facades\DB;
use Torgash\Cdek\Models\PlaceMap;

/**
 * The mapping, as a file you can keep.
 *
 * Everything else here can be rebuilt from the carrier's API; the mapping
 * cannot — some of it was decided by hand. Keyed by the registry's own `ref`
 * (`osm:n1686293227`), not by place id: ids are this database's business,
 * while a ref survives a re-import of the locations pack and a move to
 * another shop. A row whose ref is unknown here is skipped and counted.
 */
class MapSnapshot
{
    public const COLUMNS = ['place_ref', 'place_name', 'city_code', 'city_name', 'matched_by', 'confidence'];

    /** @return \Generator<int, list<string>> */
    public function rows(): \Generator
    {
        $query = DB::table('m_cdek_place_map as m')
            ->join('m_locations_places as p', 'p.id', '=', 'm.place_id')
            ->leftJoin('m_cdek_cities as c', 'c.code', '=', 'm.city_code')
            ->orderByRaw("case when m.matched_by = 'manual' then 0 else 1 end")
            ->orderBy('p.id')
            ->select([
                'p.ref as place_ref',
                DB::raw("coalesce(p.name->>'ru', p.name->>'en') as place_name"),
                'm.city_code',
                'c.name as city_name',
                'm.matched_by',
                'm.confidence',
            ]);

        foreach ($query->cursor() as $row) {
            // A place added by hand has no ref — nothing stable to key on.
            if (! $row->place_ref) {
                continue;
            }

            yield [
                $row->place_ref,
                (string) ($row->place_name ?? ''),
                (string) $row->city_code,
                (string) ($row->city_name ?? ''),
                (string) $row->matched_by,
                (string) $row->confidence,
            ];
        }
    }

    /**
     * @param  iterable<list<string>>  $rows  without the header
     * @return array{restored: int, unknown: int}
     */
    public function restore(iterable $rows): array
    {
        $restored = 0;
        $unknown = 0;
        $buffer = [];

        foreach ($rows as $row) {
            $ref = trim((string) ($row[0] ?? ''));
            $code = (int) trim((string) ($row[2] ?? ''));

            if ($ref === '' || $code <= 0) {
                continue;
            }

            $placeId = DB::table('m_locations_places')->where('ref', $ref)->value('id');

            if (! $placeId) {
                $unknown++;

                continue;
            }

            $buffer[] = [
                'place_id' => $placeId,
                'city_code' => $code,
                'matched_by' => trim((string) ($row[4] ?? '')) ?: 'manual',
                'confidence' => (int) ($row[5] ?? 100),
                'checked_at' => now(),
            ];
            $restored++;

            if (count($buffer) >= 500) {
                $this->flush($buffer);
                $buffer = [];
            }
        }

        $this->flush($buffer);

        return ['restored' => $restored, 'unknown' => $unknown];
    }

    /** @param  list<array<string, mixed>>  $rows */
    protected function flush(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        PlaceMap::upsert(array_values(array_column($rows, null, 'place_id')), ['place_id'], ['city_code', 'matched_by', 'confidence', 'checked_at']);
    }
}
