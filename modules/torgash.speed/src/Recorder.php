<?php

namespace Torgash\Speed;

use Illuminate\Support\Facades\DB;

/**
 * Writes one measured request into the aggregates — after the response has
 * gone (the terminating phase), in at most four statements, each an atomic
 * `insert … on conflict do update` so two requests finishing together add up
 * instead of overwriting each other.
 */
class Recorder
{
    /** Upper bounds of the latency buckets, ms; the last bucket is open. */
    public const BUCKETS = [50, 100, 200, 400, 800, 1600, 3200];

    public function record(Profile $profile, string $zone, string $path, int $traceThresholdMs): void
    {
        $route = mb_substr($profile->route ?? 'unmatched', 0, 190);
        $hour = now()->startOfHour()->toDateTimeString();
        $total = $profile->totalMs();
        $parts = $profile->breakdown();

        $buckets = array_fill(0, 8, 0);
        $buckets[self::bucket($total)] = 1;

        DB::statement(
            'insert into m_speed_routes (bucket_at, zone, route, requests, errors, total_ms, max_ms, boot_ms, db_ms, http_ms, hooks_ms, queries, http_calls, memory_max, h0, h1, h2, h3, h4, h5, h6, h7)
             values (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             on conflict (bucket_at, route) do update set
                requests = m_speed_routes.requests + 1,
                errors = m_speed_routes.errors + excluded.errors,
                total_ms = m_speed_routes.total_ms + excluded.total_ms,
                max_ms = greatest(m_speed_routes.max_ms, excluded.max_ms),
                boot_ms = m_speed_routes.boot_ms + excluded.boot_ms,
                db_ms = m_speed_routes.db_ms + excluded.db_ms,
                http_ms = m_speed_routes.http_ms + excluded.http_ms,
                hooks_ms = m_speed_routes.hooks_ms + excluded.hooks_ms,
                queries = m_speed_routes.queries + excluded.queries,
                http_calls = m_speed_routes.http_calls + excluded.http_calls,
                memory_max = greatest(m_speed_routes.memory_max, excluded.memory_max),
                h0 = m_speed_routes.h0 + excluded.h0, h1 = m_speed_routes.h1 + excluded.h1,
                h2 = m_speed_routes.h2 + excluded.h2, h3 = m_speed_routes.h3 + excluded.h3,
                h4 = m_speed_routes.h4 + excluded.h4, h5 = m_speed_routes.h5 + excluded.h5,
                h6 = m_speed_routes.h6 + excluded.h6, h7 = m_speed_routes.h7 + excluded.h7',
            [
                $hour, $zone, $route, $profile->status >= 500 ? 1 : 0,
                $total, $total, $parts['boot'], $parts['db'], $parts['http'], $parts['hooks'],
                $profile->queries, $profile->httpCalls, memory_get_peak_usage(true),
                ...$buckets,
            ],
        );

        $this->modules($profile, $hour, $route);
        $this->queries($profile, $route);

        if ($profile->requested || $total >= $traceThresholdMs) {
            $this->trace($profile, $zone, $route, $path, $total, $parts);
        }
    }

    public static function bucket(float $ms): int
    {
        foreach (self::BUCKETS as $i => $bound) {
            if ($ms < $bound) {
                return $i;
            }
        }

        return count(self::BUCKETS);
    }

    protected function modules(Profile $profile, string $hour, string $route): void
    {
        $rows = [];
        $bindings = [];

        foreach ($profile->modules as $module => $stats) {
            $rows[] = '(?, ?, ?, 1, ?, ?, ?)';
            array_push($bindings, $hour, $route, mb_substr($module, 0, 64), $stats['calls'], $stats['ms'], $stats['db_ms']);
        }

        if ($rows === []) {
            return;
        }

        DB::statement(
            'insert into m_speed_modules (bucket_at, route, module, requests, calls, ms, db_ms) values '.implode(', ', $rows).'
             on conflict (bucket_at, route, module) do update set
                requests = m_speed_modules.requests + 1,
                calls = m_speed_modules.calls + excluded.calls,
                ms = m_speed_modules.ms + excluded.ms,
                db_ms = m_speed_modules.db_ms + excluded.db_ms',
            $bindings,
        );
    }

    /**
     * The fifty most expensive shapes of this request — enough to hold every
     * one that matters, bounded so a page that asks two thousand different
     * things does not write two thousand rows.
     */
    protected function queries(Profile $profile, string $route): void
    {
        $shapes = $profile->queryShapes;
        uasort($shapes, fn ($a, $b) => $b['ms'] <=> $a['ms']);
        $shapes = array_slice($shapes, 0, 50, true);

        $rows = [];
        $bindings = [];
        $day = now()->toDateString();

        foreach ($shapes as $fingerprint => $q) {
            $rows[] = '(?, ?, ?, ?, 1, ?, ?, ?, ?, ?)';
            array_push($bindings, $day, $fingerprint, $route, mb_substr($q['shape'], 0, 4000),
                $q['count'], $q['ms'], $q['max'], $q['count'], $q['caller'] ? mb_substr($q['caller'], 0, 255) : null);
        }

        if ($rows === []) {
            return;
        }

        DB::statement(
            'insert into m_speed_queries (day, fingerprint, route, sql, requests, executions, total_ms, max_ms, max_repeat, caller) values '.implode(', ', $rows).'
             on conflict (day, fingerprint, route) do update set
                requests = m_speed_queries.requests + 1,
                executions = m_speed_queries.executions + excluded.executions,
                total_ms = m_speed_queries.total_ms + excluded.total_ms,
                max_ms = greatest(m_speed_queries.max_ms, excluded.max_ms),
                max_repeat = greatest(m_speed_queries.max_repeat, excluded.max_repeat),
                caller = coalesce(excluded.caller, m_speed_queries.caller)',
            $bindings,
        );
    }

    /** @param  array<string, float>  $parts */
    protected function trace(Profile $profile, string $zone, string $route, string $path, float $total, array $parts): void
    {
        $repeated = [];

        foreach ($profile->repeated() as $q) {
            $repeated[] = ['sql' => mb_substr($q['shape'], 0, 500), 'count' => $q['count'], 'ms' => round($q['ms'], 2), 'caller' => $q['caller']];
        }

        usort($repeated, fn ($a, $b) => $b['count'] <=> $a['count']);

        $modules = $profile->modules;
        uasort($modules, fn ($a, $b) => $b['ms'] <=> $a['ms']);

        DB::table('m_speed_traces')->insert([
            'created_at' => now(),
            'zone' => $zone,
            'route' => $route,
            'path' => mb_substr($path, 0, 255),
            'status' => $profile->status,
            'total_ms' => round($total, 2),
            'requested' => $profile->requested,
            'summary' => json_encode([
                'parts' => array_map(fn ($v) => round($v, 2), $parts),
                'queries' => $profile->queries,
                'http_calls' => $profile->httpCalls,
                'owner' => $profile->owner,
                'memory' => memory_get_peak_usage(true),
                'modules' => array_map(fn ($m) => ['ms' => round($m['ms'], 2), 'db_ms' => round($m['db_ms'], 2), 'calls' => $m['calls']], $modules),
                'repeated' => array_slice($repeated, 0, 20),
                'spans_dropped' => count($profile->spans) >= Profile::MAX_SPANS,
            ]),
            'spans' => json_encode($profile->spans),
        ]);
    }
}
