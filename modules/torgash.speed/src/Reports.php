<?php

namespace Torgash\Speed;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the aggregates back as answers.
 *
 * Every answer about a set of hours is ADDED UP first and interpreted after:
 * sums, maxima and histogram buckets add across hours, averages and
 * percentiles do not. A percentile is read off the summed histogram, linearly
 * inside its bucket — an estimate whose error is the bucket's width, which is
 * why the buckets double: "somewhere between 200 and 400 ms" is the precision
 * the question deserves.
 */
class Reports
{
    public const PERIODS = ['24h' => 24, '7d' => 168, '30d' => 720];

    public function since(string $period): Carbon
    {
        return now()->subHours(self::PERIODS[$period] ?? 24);
    }

    /**
     * Pages, most time consumed first: a page opened a hundred thousand times
     * at 80 ms costs the shop more than a report opened twice at three
     * seconds, and this is the list that says so.
     *
     * @return Collection<int, object>
     */
    public function routes(Carbon $since, ?Carbon $until = null, ?string $zone = null): Collection
    {
        $rows = $this->routeSums($since, $until)
            ->when($zone, fn ($q) => $q->where('zone', $zone))
            ->groupBy('route', 'zone')
            ->addSelect('route', 'zone')
            ->get();

        return $rows->map(fn ($row) => $this->interpret($row))->sortByDesc('total_ms')->values();
    }

    /**
     * One number per zone (storefront, admin, API) — what the dashboard shows.
     *
     * @return array<string, object>
     */
    public function zones(Carbon $since, ?Carbon $until = null): array
    {
        return $this->routeSums($since, $until)
            ->groupBy('zone')
            ->addSelect('zone')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->zone => $this->interpret($row)])
            ->all();
    }

    /** One route, summed over the period. */
    public function route(string $route, Carbon $since): ?object
    {
        $row = $this->routeSums($since)->where('route', $route)->groupBy('route', 'zone')->addSelect('route', 'zone')->first();

        return $row ? $this->interpret($row) : null;
    }

    /**
     * A route day by day — the trend a regression shows up in.
     *
     * @return Collection<int, object>
     */
    public function daily(string $route, Carbon $since): Collection
    {
        return $this->routeSums($since)
            ->where('route', $route)
            ->selectRaw('date(bucket_at) as day')
            ->groupByRaw('date(bucket_at)')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => $this->interpret($row));
    }

    /**
     * What got slower: each busy page's mean over the last seven days against
     * the seven before, biggest growth first. Pages seen too rarely in either
     * week say nothing and are left out.
     *
     * @return Collection<int, object>
     */
    public function grown(int $minRequests = 20): Collection
    {
        $now = $this->routes(now()->subDays(7))->keyBy('route');
        $before = $this->routes(now()->subDays(14), now()->subDays(7))->keyBy('route');

        return $now
            ->filter(fn ($r) => $r->requests >= $minRequests && ($before[$r->route]->requests ?? 0) >= $minRequests)
            ->map(function ($r) use ($before) {
                $r->was_ms = $before[$r->route]->avg_ms;
                $r->growth = $r->was_ms > 0 ? $r->avg_ms / $r->was_ms - 1 : 0;

                return $r;
            })
            ->filter(fn ($r) => $r->growth > 0.15)
            ->sortByDesc('growth')
            ->values();
    }

    /**
     * Modules by the time their hooks take, and what that is per page they
     * run on.
     *
     * @return Collection<int, object>
     */
    public function modules(Carbon $since, ?string $route = null): Collection
    {
        $total = (float) DB::table('m_speed_routes')->where('bucket_at', '>=', $since)
            ->when($route, fn ($q) => $q->where('route', $route))->sum('total_ms');

        return DB::table('m_speed_modules')
            ->where('bucket_at', '>=', $since)
            ->when($route, fn ($q) => $q->where('route', $route))
            ->groupBy('module')
            ->selectRaw('module, sum(requests) as requests, sum(calls) as calls, sum(ms) as ms, sum(db_ms) as db_ms')
            ->orderByDesc('ms')
            ->get()
            ->map(function ($m) use ($total) {
                $m->per_request = $m->requests > 0 ? $m->ms / $m->requests : 0;
                $m->share = $total > 0 ? $m->ms / $total : 0;

                return $m;
            });
    }

    /**
     * Query shapes by the time they cost in total, with where they were asked
     * from. `max_repeat` is the N+1 signal.
     *
     * @return Collection<int, object>
     */
    public function queries(Carbon $since, ?string $route = null, int $limit = 25): Collection
    {
        return DB::table('m_speed_queries')
            ->where('day', '>=', $since->toDateString())
            ->when($route, fn ($q) => $q->where('route', $route))
            ->groupBy('fingerprint')
            ->selectRaw("fingerprint, max(sql) as sql, sum(executions) as executions, sum(requests) as requests,
                sum(total_ms) as total_ms, max(max_ms) as max_ms, max(max_repeat) as max_repeat,
                max(caller) as caller, string_agg(distinct route, ', ') as routes")
            ->orderByDesc('total_ms')
            ->limit($limit)
            ->get()
            ->map(function ($q) {
                $q->per_request = $q->requests > 0 ? $q->executions / $q->requests : 0;

                return $q;
            });
    }

    /**
     * Shapes asked again and again while one page was built — the loops.
     *
     * @return Collection<int, object>
     */
    public function repeated(Carbon $since, int $threshold, ?string $route = null): Collection
    {
        return DB::table('m_speed_queries')
            ->where('day', '>=', $since->toDateString())
            ->where('max_repeat', '>=', $threshold)
            ->when($route, fn ($q) => $q->where('route', $route))
            ->groupBy('fingerprint', 'route')
            ->selectRaw('fingerprint, route, max(sql) as sql, max(max_repeat) as max_repeat, sum(executions) as executions,
                sum(requests) as requests, sum(total_ms) as total_ms, max(caller) as caller')
            ->orderByDesc('max_repeat')
            ->limit(30)
            ->get()
            ->map(function ($q) {
                $q->per_request = $q->requests > 0 ? $q->executions / $q->requests : 0;

                return $q;
            });
    }

    /** @return Collection<int, object> */
    public function traces(Carbon $since, ?string $route = null, int $limit = 30): Collection
    {
        return DB::table('m_speed_traces')
            ->where('created_at', '>=', $since)
            ->when($route, fn ($q) => $q->where('route', $route))
            ->orderByDesc('requested')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'created_at', 'zone', 'route', 'path', 'status', 'total_ms', 'requested']);
    }

    // ---------------------------------------------------------------- helpers

    protected function routeSums(Carbon $since, ?Carbon $until = null)
    {
        $buckets = implode(', ', array_map(fn ($i) => "sum(h{$i}) as h{$i}", range(0, 7)));

        return DB::table('m_speed_routes')
            ->where('bucket_at', '>=', $since)
            ->when($until, fn ($q) => $q->where('bucket_at', '<', $until))
            ->selectRaw("sum(requests) as requests, sum(errors) as errors, sum(total_ms) as total_ms, max(max_ms) as max_ms,
                sum(boot_ms) as boot_ms, sum(db_ms) as db_ms, sum(http_ms) as http_ms, sum(hooks_ms) as hooks_ms,
                sum(queries) as queries, sum(http_calls) as http_calls, max(memory_max) as memory_max, {$buckets}");
    }

    /** Sums into what a person reads: averages, percentiles, shares. */
    protected function interpret(object $row): object
    {
        $n = max(1, (int) $row->requests);
        $histogram = array_map(fn ($i) => (int) $row->{'h'.$i}, range(0, 7));

        $row->requests = (int) $row->requests;
        $row->total_ms = (float) $row->total_ms;
        $row->avg_ms = $row->total_ms / $n;
        $row->p50 = self::percentile($histogram, 0.50, (float) $row->max_ms);
        $row->p95 = self::percentile($histogram, 0.95, (float) $row->max_ms);
        $row->queries_per_request = (int) $row->queries / $n;
        $row->histogram = $histogram;

        $parts = [
            'boot' => (float) $row->boot_ms,
            'db' => (float) $row->db_ms,
            'http' => (float) $row->http_ms,
            'hooks' => (float) $row->hooks_ms,
        ];
        $parts['rest'] = max(0.0, $row->total_ms - array_sum($parts));
        $row->parts = array_map(fn ($ms) => $ms / $n, $parts);

        return $row;
    }

    /**
     * The p-th percentile from bucket counts, interpolated inside the bucket
     * it falls in. The open last bucket is bounded by the slowest request
     * actually seen.
     *
     * @param  list<int>  $histogram
     */
    public static function percentile(array $histogram, float $p, float $max): float
    {
        $total = array_sum($histogram);

        if ($total === 0) {
            return 0.0;
        }

        $target = $p * $total;
        $seen = 0;
        $bounds = [0, ...Recorder::BUCKETS, max($max, Recorder::BUCKETS[count(Recorder::BUCKETS) - 1])];

        foreach ($histogram as $i => $count) {
            if ($count > 0 && $seen + $count >= $target) {
                $low = $bounds[$i];
                $high = min($bounds[$i + 1], max($max, $low));

                return $low + ($high - $low) * (($target - $seen) / $count);
            }

            $seen += $count;
        }

        return $max;
    }
}
