<?php

namespace App\Core\System;

use App\Core\Assets\StyleBuilder;
use App\Core\Support\WebWorkers;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * What has to happen once the code on disk is different (PLAN §45.4) — one
 * place, so a module installed from the admin, one enabled from the console
 * and (later) a core update all leave the shop in the same state:
 *
 * - the caches built FROM code are dropped: routes, config, events, compiled
 *   views (a module's routes, its templates). NOT the application cache —
 *   that holds data (sign-in pauses, rates), not code;
 * - opcache forgets the old files where this process can reach it (from the
 *   console only the console's own; a web server with timestamp checks off
 *   needs its own restart, the System page says so);
 * - every queue worker is told to finish its job and exit: a worker keeps the
 *   code it booted with, and the supervisor starts a fresh one — and the
 *   web server's workers likewise, under FrankenPHP's worker mode
 *   (WebWorkers);
 * - the stylesheet is built again, because new templates may use new classes.
 *
 * Each step is attempted on its own: a server that cannot build styles still
 * gets its routes refreshed. Off in the test suite (`torgash.refresh_on_change`),
 * which installs every module and must not rebuild a stylesheet forty times.
 */
class CodeChanged
{
    /** @return list<string> what was done */
    public function refresh(): array
    {
        if (! config('torgash.refresh_on_change', true)) {
            return [];
        }

        $done = [];

        foreach (['config:clear', 'route:clear', 'event:clear', 'view:clear'] as $command) {
            try {
                Artisan::call($command);
                $done[] = $command;
            } catch (Throwable) {
                // The next step does not depend on this one.
            }
        }

        if (function_exists('opcache_reset') && @opcache_reset()) {
            $done[] = 'opcache';
        }

        try {
            Artisan::call('queue:restart');
            $done[] = 'queue:restart';
        } catch (Throwable) {
        }

        // The web server's workers keep their code the same way the queue's
        // does (FrankenPHP's worker mode); nothing to do without one.
        $done = [...$done, ...WebWorkers::restart()];

        try {
            app(StyleBuilder::class)->build();
            $done[] = 'styles';
        } catch (Throwable) {
            // Remembered by the builder itself; the System page shows it.
        }

        // Pages kept for guests were drawn by the old code (PLAN §61).
        app(\App\Core\PageCache\PageCache::class)->flush('the code changed');
        $done[] = 'pages';

        return $done;
    }
}
