<?php

namespace Torgash\Speed\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Speed\Meter;
use Torgash\Speed\Reports;

/**
 * Three screens, from the whole shop down to one request: where the time goes
 * (pages, modules, queries, loops), one page over time, one page's timeline.
 */
class SpeedController extends Controller
{
    public function __construct(protected Reports $reports) {}

    public function index(Request $request, Meter $meter)
    {
        $period = $this->period($request);
        $since = $this->reports->since($period);
        $zone = in_array($request->query('zone'), ['storefront', 'admin', 'api'], true) ? $request->query('zone') : null;

        $routes = $this->reports->routes($since, zone: $zone);

        return view('torgash.speed::admin.index', [
            'period' => $period,
            'zone' => $zone,
            'zones' => $this->reports->zones($since),
            'routes' => $routes->take(40),
            'routesTotal' => $routes->sum('total_ms'),
            'grown' => $this->reports->grown()->take(8),
            'modules' => $this->reports->modules($since),
            'queries' => $this->reports->queries($since, limit: 15),
            'repeated' => $this->reports->repeated($since, $this->repeatThreshold()),
            'traces' => $this->reports->traces($since, limit: 15),
            'tracingUntil' => $meter->requestedUntil($request->ip()),
            'enabled' => (bool) setting('torgash.speed', 'enabled'),
            'percent' => (int) setting('torgash.speed', 'sample_percent'),
        ]);
    }

    public function route(Request $request)
    {
        $route = (string) $request->query('r', '');
        $period = $this->period($request, default: '7d');
        $since = $this->reports->since($period);
        $summary = $this->reports->route($route, $since);

        abort_if($route === '' || $summary === null, 404);

        return view('torgash.speed::admin.route', [
            'route' => $route,
            'period' => $period,
            'summary' => $summary,
            'daily' => $this->reports->daily($route, $since),
            'modules' => $this->reports->modules($since, $route),
            'queries' => $this->reports->queries($since, $route, 20),
            'repeated' => $this->reports->repeated($since, $this->repeatThreshold(), $route),
            'traces' => $this->reports->traces($since, $route, 20),
        ]);
    }

    public function trace(int $trace)
    {
        $row = DB::table('m_speed_traces')->find($trace);

        abort_if($row === null, 404);

        $row->summary = json_decode($row->summary, true);
        $row->spans = json_decode($row->spans, true);

        return view('torgash.speed::admin.trace', ['trace' => $row]);
    }

    /** "Trace my pages for ten minutes" — every request from this address, timelines kept. */
    public function traceMe(Request $request, Meter $meter)
    {
        $meter->request((string) $request->ip(), 10);

        return back()->with('status', __('For the next ten minutes every page you open is measured and its timeline kept. Open the shop in another tab.'));
    }

    public function stopTraceMe(Request $request, Meter $meter)
    {
        $meter->stopRequest($request->ip());

        return back()->with('status', __('Tracing stopped.'));
    }

    protected function period(Request $request, string $default = '24h'): string
    {
        $period = (string) $request->query('period', $default);

        return array_key_exists($period, Reports::PERIODS) ? $period : $default;
    }

    protected function repeatThreshold(): int
    {
        return max(2, (int) setting('torgash.speed', 'repeat_threshold'));
    }
}
