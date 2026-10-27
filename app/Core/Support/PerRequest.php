<?php

namespace App\Core\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Bindings that must be BUILT AGAIN for every request, not merely scoped.
 *
 * `scoped()` is the right declaration — it says "one per request" — but
 * something has to actually forget the instance, and only Octane does
 * (`forgetScopedInstances()` between requests). Everywhere else that reuses one
 * application for several requests, the object simply stays: php-fpm never
 * notices because it builds a new application each time, and the test suite
 * notices in the worst possible way — the second request in a test gets the
 * first one's object.
 *
 * That is not a test artefact. App\Core\Seo\PageMeta answers its filter once
 * and remembers it did; handed to a second request it quietly prints the first
 * page's description. So the reset is hung on the one event that means "a new
 * request has begun" and happens in every environment: the container rebinding
 * `request`.
 *
 * Use it for anything that accumulates state ABOUT the current page.
 *
 * A queue job is the same kind of beginning — a worker runs for days and
 * never rebinds `request` — so the same reset runs before every job, and
 * `flushPerJob()` empties the memos of small tables there too (Currencies,
 * settings, Labels): a setting changed in the admin has to reach the next
 * job, and before 2026-10-21 a long-running worker served the settings it
 * had booted with — "Install the update" saw an update check without the
 * version the owner had just been offered.
 */
class PerRequest
{
    public static function forget(Application $app, string ...$abstracts): void
    {
        $reset = function ($container = null) use ($app, $abstracts) {
            foreach ($abstracts as $abstract) {
                // The container the request was rebound ON: under a worker
                // that is the clone serving this request as well as the booted
                // original, and forgetting only in the original left the
                // clone's copy in place.
                ($container instanceof Application ? $container : $app)->forgetInstance($abstract);
            }
        };

        $app->rebinding('request', fn ($container) => $reset($container));
        $app['events']->listen(JobProcessing::class, function (JobProcessing $event) use ($reset) {
            // A `sync` job runs INSIDE the request that dispatched it: resetting
            // here would wipe that page's own state half way through drawing it.
            if ($event->connectionName !== 'sync') {
                $reset();
            }
        });
    }

    /**
     * Memos of small tables, emptied before every queue job: the SAME
     * instance (other objects hold it, and SettingsManager keeps the
     * defaults registered at boot), its rows dropped through its own
     * `flush()`. Only what has been resolved is touched. Not per request: a
     * php-fpm request starts empty anyway, and the test suite measures
     * queries from a warm second request on purpose.
     */
    public static function flushPerJob(Application $app, string ...$abstracts): void
    {
        $flush = function (Application $container) use ($abstracts) {
            foreach ($abstracts as $abstract) {
                if ($container->resolved($abstract)) {
                    $container->make($abstract)->flush();
                }
            }
        };

        $app['events']->listen(JobProcessing::class, function (JobProcessing $event) use ($app, $flush) {
            if ($event->connectionName !== 'sync') {
                $flush($app);
            }
        });

        // A persistent web worker is the same case as a queue worker: these
        // memos were filled by an earlier request, and a setting the admin
        // changed through one worker must reach the others. Octane's events
        // fire only under Octane, so php-fpm and the tests keep their warm
        // second request (see the docblock above).
        foreach (WorkerMode::BEGINS as $begins) {
            $app['events']->listen($begins, fn ($event) => $flush($event->sandbox));
        }
    }
}
