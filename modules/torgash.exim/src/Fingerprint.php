<?php

namespace Torgash\Exim;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Has anything this export reads changed since its file was built?" — asked
 * every five minutes for a profile that rebuilds on a change, so it must be
 * cheap and must not take a visit for an edit.
 *
 * The tables an entity's export reads are named by the module that owns
 * them, `exim.watch.<entity>` (catalog: products, categories,
 * characteristics, prices, photos, currencies), and a module extending
 * somebody else's entity adds its own to the same list. With no answer, the
 * entity's own table. Per table:
 *
 *  - with `updated_at`: the row count and the latest `updated_at` — an edit
 *    moves the second, a deletion the first;
 *  - without one (a pivot — a product moved to another category): the row
 *    count and the sum of every row's hash, which no swap of two rows
 *    survives.
 *
 * NOT Postgres's own counters of written rows (`pg_stat_user_tables`), which
 * would be cheaper and blind to nothing — that is the trouble with them: a
 * product's popularity is written on every page view (`Popularity`, which
 * leaves `updated_at` alone for exactly this), so the counter would call
 * every visit a change and a busy shop would rebuild every time it may.
 *
 * `updated_at` is kept to the second, so a write in the very second a
 * fingerprint is taken could hide behind it: a table written to in the last
 * two seconds makes the answer null — "cannot tell", which every caller
 * reads as "changed". Never the other way round: a missed change leaves a
 * marketplace with yesterday's prices, an extra build costs a minute.
 */
class Fingerprint
{
    /** A table written to this recently may be written to again within the same second. */
    protected const SETTLING_SECONDS = 2;

    /**
     * What `$entity`'s export reads, as the modules declared it; only tables
     * that exist (a declared one can belong to a module not installed here).
     *
     * @return list<string>
     */
    public static function tables(string $entity): array
    {
        $tables = (array) hook_filter("exim.watch.{$entity}", [], ['entity' => $entity]);

        if ($tables === []) {
            $query = hook_filter("exim.query.{$entity}", null, ['filters' => []]);
            $tables = match (true) {
                $query instanceof EloquentBuilder => [$query->getModel()->getTable()],
                $query instanceof QueryBuilder && is_string($query->from) => [$query->from],
                default => [],
            };
        }

        return array_keys(self::columns(array_values(array_unique(array_map('strval', $tables)))));
    }

    /** The state of what `$entity`'s export reads, or null when it cannot be told. */
    public static function of(string $entity): ?string
    {
        $tables = self::columns(self::tables($entity));

        if ($tables === []) {
            return null;
        }

        $parts = [];
        $n = 0;

        foreach ($tables as $table => $timestamped) {
            $wrapped = DB::getQueryGrammar()->wrapTable($table);
            $parts[] = $timestamped
                ? "select {$n} as n, count(*)::text as rows, max(updated_at)::text as state from {$wrapped}"
                : "select {$n} as n, count(*)::text as rows, coalesce(sum(hashtext(t::text)), 0)::text as state from {$wrapped} t";
            $n++;
        }

        $rows = collect(DB::select(implode(' union all ', $parts).' order by n'));
        $settled = now()->subSeconds(self::SETTLING_SECONDS);

        foreach (array_values($tables) as $i => $timestamped) {
            $state = $rows[$i]->state ?? null;

            if ($timestamped && $state !== null && Carbon::parse($state)->gte($settled)) {
                return null;
            }
        }

        return md5(json_encode([array_keys($tables), $rows->map(fn ($row) => [$row->rows, $row->state])->all()]));
    }

    /**
     * Which of `$tables` exist, and whether each keeps `updated_at` — one
     * look at the catalogue for all of them.
     *
     * @param  list<string>  $tables
     * @return array<string, bool> in the order asked
     */
    protected static function columns(array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $found = collect(DB::select(
            'select table_name, bool_or(column_name = ?) as timestamped from information_schema.columns
             where table_schema = current_schema() and table_name = any(?::text[]) group by table_name',
            ['updated_at', '{'.implode(',', array_map(fn ($table) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $table).'"', $tables)).'}'],
        ))->mapWithKeys(fn ($row) => [$row->table_name => (bool) $row->timestamped]);

        $columns = [];

        foreach ($tables as $table) {
            if ($found->has($table)) {
                $columns[$table] = $found[$table];
            }
        }

        return $columns;
    }
}
