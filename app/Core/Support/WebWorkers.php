<?php

namespace App\Core\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Telling FrankenPHP's workers that the code under them has changed.
 *
 * A worker boots the application once and keeps it — the routes, the module
 * list, every class it has loaded. After a module is installed or the engine
 * updated it would go on serving the old code until restarted, and the user it
 * runs as cannot restart the server. Two ways, both used:
 *
 * 1. A stamp file (`STAMP`). The worker loop (FrankenPhpWorker) reads it when the
 *    worker boots and again after every request; once it differs, the worker
 *    finishes and FrankenPHP starts a fresh one. Needs nothing from the
 *    server's configuration, works for every worker thread, and costs one
 *    stat per request. Its price: each worker serves ONE more request on the
 *    old code before it notices.
 * 2. Caddy's admin endpoint, when the shop is told where it is
 *    (`TORGASH_WORKER_RESTART_URL`, e.g.
 *    http://localhost:2019/frankenphp/workers/restart): every worker restarts
 *    at once. Only from the console — the update command, module:install.
 *    From inside a worker it would wait for every worker to finish, the
 *    asking one included, which is waiting for it.
 */
final class WebWorkers
{
    /** Relative to storage_path(). Read by the worker script without Laravel. */
    public const STAMP = 'framework/workers-version';

    /** @return list<string> what was done */
    public static function restart(): array
    {
        $done = [];

        try {
            file_put_contents(storage_path(self::STAMP), bin2hex(random_bytes(8)), LOCK_EX);
            $done[] = 'web-workers:stamp';
        } catch (Throwable) {
            // A shop with no writable storage has bigger problems; nothing to undo.
        }

        $url = (string) config('torgash.worker_restart_url', '');

        if ($url !== '' && ! WorkerMode::active()) {
            try {
                Http::timeout(5)->post($url)->throw();
                $done[] = 'web-workers:restart';
            } catch (Throwable) {
                // The stamp above gets there anyway, one request later.
            }
        }

        return $done;
    }
}
