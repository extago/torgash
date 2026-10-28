<?php

namespace Torgash\Speed;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the meter to what the framework already announces — a query ran, a
 * route matched, an outside call went and came back, the response is built —
 * and to the hook bus through its observer. Every listener asks
 * `Meter::profile()` first and does nothing when the request is not measured.
 *
 * Only HTTP requests are measured: a console command or a queue worker has no
 * page, and its work is what "Background tasks" already shows.
 */
class SpeedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Meter::class);
        $this->app->singleton(Recorder::class);
    }

    public function boot(): void
    {
        // What is measured is on no guest's page (PLAN §61).
        page_cache()->unseen(['m_speed_modules', 'm_speed_queries', 'm_speed_routes', 'm_speed_traces']);

        Route::middleware(['web', 'auth', 'can:speed.view'])
            ->prefix('admin/speed')
            ->name('admin.speed.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        hook_listen('core.admin.dashboard.widgets', fn (array $widgets) => [...$widgets, [
            'key' => 'speed.storefront',
            'title' => 'Storefront speed',
            'icon' => 'gauge',
            'size' => 'wide',
            'position' => 80,
            'can' => 'speed.view',
            'link' => ['url' => route('admin.speed.index'), 'label' => 'Where the time goes'],
            'render' => fn () => app(Http\Admin\DashboardCard::class)->render(bare: true),
        ]]);

        hook_listen('core.schedule', function (array $context) {
            $context['schedule']->call(fn () => app(Prune::class)->run())->dailyAt('03:40')->name('torgash.speed.prune');
        });

        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return;
        }

        $this->listen();

        // The first request of this process is already here, and began when
        // PHP did — LARAVEL_START — so starting the engine is part of what it
        // cost. Any later request in a long-lived process (a test run, a
        // persistent worker) begins when the container is handed it.
        // (Not under tests: there the boot-time request is not a page, and the
        // test's own request arrives through the rebinding below.)
        if (! $this->app->runningUnitTests()) {
            $start = defined('LARAVEL_START') ? (float) LARAVEL_START : microtime(true);
            $bootMs = max(0.0, (microtime(true) - $start) * 1000);

            app(Meter::class)->arm($this->app['request'], hrtime(true) - (int) ($bootMs * 1e6), $bootMs);
        }

        $this->app->rebinding('request', function ($app, $request) {
            app(Meter::class)->arm($request, hrtime(true));
        });
    }

    protected function listen(): void
    {
        DB::listen(function (QueryExecuted $query) {
            app(Meter::class)->profile()?->query($query->sql, (float) $query->time);
        });

        Event::listen(RouteMatched::class, function (RouteMatched $event) {
            app(Meter::class)->route($event->route->getName(), $event->route->uri(), $event->route->getActionName());
        });

        Event::listen(RequestSending::class, function (RequestSending $event) {
            app(Meter::class)->profile()?->httpStart($event->request);
        });

        Event::listen(ResponseReceived::class, function (ResponseReceived $event) {
            app(Meter::class)->profile()?->httpEnd($event->request, $event->request->method().' '.self::host($event->request->url()).' → '.$event->response->status());
        });

        Event::listen(ConnectionFailed::class, function (ConnectionFailed $event) {
            app(Meter::class)->profile()?->httpEnd($event->request, $event->request->method().' '.self::host($event->request->url()).' → failed');
        });

        Event::listen(RequestHandled::class, function (RequestHandled $event) {
            app(Meter::class)->handled($event->response->getStatusCode());
        });

        $this->app->terminating(function () {
            app(Meter::class)->finish($this->app['request']);
        });
    }

    /** Host and path only — a query string on an outside call carries keys. */
    protected static function host(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['host'] ?? '?').($parts['path'] ?? '');
    }
}
