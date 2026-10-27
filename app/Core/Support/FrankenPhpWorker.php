<?php

namespace App\Core\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\FrankenPhp\FrankenPhpClient;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Stream;
use Laravel\Octane\Worker;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Torgash under FrankenPHP's worker mode: one application booted once and
 * kept, serving request after request (Laravel Octane does the serving).
 *
 * public/index.php hands over to this when FrankenPHP runs it as a worker, so
 * the Caddyfile names the ordinary front controller:
 *
 *     php_server {
 *         worker index.php
 *     }
 *
 * Not a file of its own in public/ — that is what it first was, and no shop
 * could install it: an update may replace only the paths the INSTALLED code
 * lets it own (Distribution::ownable), and 0.2.0 had never heard of a new one
 * (found installing 0.2.1 on the first live shop). index.php was always owned.
 *
 * It is Octane's own worker loop (vendor/laravel/octane/bin/frankenphp-worker.php)
 * with one addition: after every request it reads the stamp the engine writes
 * when its code changes (WebWorkers) and, once it differs from the one this
 * worker booted with, stops — FrankenPHP starts a fresh worker on the new code.
 */
final class FrankenPhpWorker
{
    /** Is this process FrankenPHP running the front controller as a worker? */
    public static function wanted(): bool
    {
        return (bool) ($_SERVER['FRANKENPHP_WORKER'] ?? false) && function_exists('frankenphp_handle_request');
    }

    public static function run(string $basePath): void
    {
        ignore_user_abort(true);
        ini_set('display_errors', 'stderr');
        $_ENV['APP_RUNNING_IN_CONSOLE'] = false;

        // The same path as WebWorkers::STAMP, read without Laravel.
        $stampFile = $basePath.'/storage/'.WebWorkers::STAMP;
        $readStamp = static function () use ($stampFile): string {
            clearstatcache(true, $stampFile);

            return is_file($stampFile) ? (string) @file_get_contents($stampFile) : '';
        };
        $bootStamp = $readStamp();

        $client = new FrankenPhpClient;

        try {
            $worker = tap(new Worker(new ApplicationFactory($basePath), $client))->boot();
        } catch (Throwable $e) {
            self::bootFailed($e);

            exit(1);
        }

        $requests = 0;
        $debug = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? 'false';
        // A worker is retired after this many requests whatever happens:
        // whatever a library leaks, it leaks for a bounded time.
        $maxRequests = (int) ($_ENV['MAX_REQUESTS'] ?? $_SERVER['MAX_REQUESTS'] ?? 1000);
        $maxTime = $_ENV['REQUEST_MAX_EXECUTION_TIME'] ?? $_SERVER['REQUEST_MAX_EXECUTION_TIME'] ?? null;

        if (PHP_OS_FAMILY === 'Linux' && $maxTime !== null) {
            set_time_limit((int) $maxTime);
        }

        $handle = static function () use ($worker, $client, $debug) {
            try {
                [$request, $context] = $client->marshalRequest(new RequestContext);

                $worker->handle($request, $context);
            } catch (Throwable $e) {
                report($e);

                (new Response(
                    $debug === 'true' ? (string) $e : 'Internal Server Error',
                    500,
                    ['Status' => '500 Internal Server Error', 'Content-Type' => 'text/plain'],
                ))->send();

                Stream::shutdown($e);
            }
        };

        try {
            while ($requests < $maxRequests && @frankenphp_handle_request($handle)) {
                $requests++;

                // The code changed under us (an update, a module installed):
                // go, and let FrankenPHP start a worker on the new code.
                if ($readStamp() !== $bootStamp) {
                    break;
                }
            }
        } finally {
            $worker->terminate();

            gc_collect_cycles();
        }
    }

    protected static function bootFailed(Throwable $e): void
    {
        $line = sprintf("[torgash worker boot] %s: %s\n  in %s:%d\n", $e::class, $e->getMessage(), $e->getFile(), $e->getLine());

        try {
            $container = Container::getInstance();

            if ($container->bound(ExceptionHandler::class)) {
                $container->make(ExceptionHandler::class)->renderForConsole(new ConsoleOutput, $e);

                return;
            }
        } catch (Throwable) {
        }

        fwrite(STDERR, $line);
    }
}
