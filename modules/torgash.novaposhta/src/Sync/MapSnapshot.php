<?php

namespace Torgash\NovaPoshta\Sync;

use Illuminate\Support\Facades\DB;
use Torgash\NovaPoshta\Models\PlaceMap;

/**
 * The mapping, as a file you can keep.
 *
 * Everything else in this module can be rebuilt from the carrier's API — the
 * settlement directory, the branch cache. The mapping cannot: it is 26 000
 * decisions, some of them made by hand, and it is the one thing that is lost if
 * the module is uninstalled, a re-sync goes wrong, or the shop moves servers
 * (PLAN §12.9).
 *
 * Keyed by the registry's own `ref` (`osm:n26150422`), not by place id: ids are
 * this database's business, while a ref survives a re-import of the locations
 * pack and even a move to another shop. A row whose ref is unknown here is
 * skipped and counted, never guessed at.
 */
class MapSnapshot
{
    public const COLUMNS = ['place_ref', 'place_name', 'settlement_ref', 'city_ref', 'matched_by', 'confidence'];

    /**
     * Rows for the file, newest decisions first so a human opening it sees what
     * was bound by hand at the top.
     *
     * @return \Generator<int, list<string>>
     */
    public function rows(): \Generator
    {
        $query = DB::table('m_np_place_map as m')
            ->join('m_locations_places as p', 'p.id', '=', 'm.place_id')
            ->leftJoin('m_np_settlements as s', 's.ref', '=', 'm.settlement_ref')
            ->orderByRaw("case when m.matched_by = 'manual' then 0 else 1 end")
            ->orderBy('p.id')
            ->select([
                'p.ref as place_ref',
                DB::raw("coalesce(p.name->>'uk', p.name->>'en') as place_name"),
                'm.settlement_ref',
                'm.city_ref',
                'm.matched_by',
                'm.confidence',
                's.name_uk as settlement_name',
            ]);

        foreach ($query->cursor() as $row) {
            // A place with no ref is one a human added by hand to the registry;
            // it has nothing stable to key on, so it stays out of the file
            // rather than coming back attached to the wrong row.
            if (! $row->place_ref) {
                continue;
            }

            yield [
                $row->place_ref,
                (string) ($row->place_name ?? ''),
                trim((string) $row->settlement_ref),
                trim((string) ($row->city_ref ?? '')),
                (string) $row->matched_by,
                (string) $row->confidence,
            ];
        }
    }

    /**
     * Restores what such a file holds.
     *
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
            $settlement = trim((string) ($row[2] ?? ''));

            if ($ref === '' || $settlement === '') {
                continue;
            }

            $placeId = DB::table('m_locations_places')->where('ref', $ref)->value('id');

            if (! $placeId) {
                $unknown++;

                continue;
            }

            $buffer[] = [
                'place_id' => $placeId,
                'settlement_ref' => $settlement,
                'city_ref' => trim((string) ($row[3] ?? '')) ?: null,
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

        PlaceMap::upsert($rows, ['place_id'], ['settlement_ref', 'city_ref', 'matched_by', 'confidence', 'checked_at']);
    }
}
