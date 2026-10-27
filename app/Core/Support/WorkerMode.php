<?php

namespace App\Core\Support;

use App\Core\Hooks\HookBus;
use App\Core\Themes\ThemeManager;
use App\Core\Zones\ZoneRegistry;
use Illuminate\Contracts\Foundation\Application;

/**
 * What a persistent worker (FrankenPHP's worker mode, through Laravel Octane)
 * has to put back before every request.
 *
 * Octane clones the booted application for each request, so whatever is
 * RESOLVED during a request dies with it. What was resolved while booting is
 * not cloned — the same object serves every request of the worker — and some
 * of ours are changed by a request on purpose:
 *
 * - the hook bus: a listener added at request time (the admin's "test this
 *   code channel") would stay subscribed for every later request;
 * - the theme: a storefront on its own theme rewires the shared view finder,
 *   and the admin or a letter served next would draw in it;
 * - the translator's paths: a theme's translations are ADDED (the themes
 *   screen adds every theme's), and would pile up across storefronts;
 * - the zone list: a theme's zones, the same way.
 *
 * Each is remembered as it stood when the worker finished booting
 * (`WorkerStarting`) and put back when a request, task or tick begins. Memos
 * of small tables are emptied there too (PerRequest::flushPerJob): a setting
 * the admin changed through one worker must reach the others.
 *
 * Nothing here runs outside a worker — Octane's events are only dispatched by
 * Octane — so php-fpm, the console and the test suite are untouched. The
 * worker tests (Tests\Concerns\RunsInOctaneWorker) are what keep it honest.
 */
final class WorkerMode
{
    /** Octane's "an operation begins" events — a request, a task, a tick. */
    public const BEGINS = [
        'Laravel\Octane\Events\RequestReceived',
        'Laravel\Octane\Events\TaskReceived',
        'Laravel\Octane\Events\TickReceived',
    ];

    public static function register(Application $app): void
    {
        if (! class_exists('Laravel\Octane\Events\WorkerStarting')) {
            return;
        }

        $events = $app['events'];

        $events->listen('Laravel\Octane\Events\WorkerStarting', function ($event) {
            self::freeze($event->app);
        });

        foreach (self::BEGINS as $begins) {
            $events->listen($begins, function ($event) {
                self::thaw($event->sandbox);
            });
        }
    }

    /** Is this process a FrankenPHP worker? */
    public static function active(): bool
    {
        return (bool) ($_SERVER['FRANKENPHP_WORKER'] ?? false);
    }

    protected static function freeze(Application $app): void
    {
        $app->make(HookBus::class)->freeze();
        $app->make(ZoneRegistry::class)->freeze();

        $loader = $app->make('translator')->getLoader();
        $app->instance('torgash.worker.json_paths', $loader->jsonPaths());
    }

    protected static function thaw(Application $app): void
    {
        // The theme first: going back to the default one rewires the finder
        // and adds that theme's translations and zones — which the two
        // restores below then put back to exactly the booted state.
        if ($app->resolved(ThemeManager::class)) {
            $app->make(ThemeManager::class)->reset();
        }

        $app->make(HookBus::class)->thaw();
        $app->make(ZoneRegistry::class)->thaw();

        if ($app->bound('torgash.worker.json_paths')) {
            $translator = $app->make('translator');
            $loader = $translator->getLoader();
            $booted = $app->make('torgash.worker.json_paths');

            if ($loader->jsonPaths() !== $booted) {
                (fn () => $this->jsonPaths = $booted)->call($loader);
                // What was loaded through the extra paths is in memory too.
                $translator->setLoaded([]);
            }
        }
    }
}
