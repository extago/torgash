<?php

namespace App\Core\Support;

use Throwable;

/**
 * The shop's time zone, as the application's own clock.
 *
 * Until 2026-10-21 the application ran on UTC whatever the shop was: the core
 * setting `timezone` only told the display rules what "from 18:00" means,
 * while an order placed at 23:20 in Moscow was printed as 20:20 (found on the
 * first live shop). Now the setting is the clock of everything — order times,
 * letters, the database's own `now()` — and since that has to be known before
 * the database can be read, it lives in .env as APP_TIMEZONE (config/app.php,
 * and the pgsql connection's `timezone`). The installer writes it from the
 * country; changing the setting writes it here.
 *
 * Times already stored are not moved: a timestamp column without a zone keeps
 * the wall-clock hour it was written with, so a shop changes its zone before
 * its first orders, not after.
 */
final class ShopClock
{
    /**
     * A shop that chose its zone BEFORE the zone became the application's
     * clock (0.2.0 and older: the setting only steered display rules) still
     * runs on UTC — and re-saving the same zone announces nothing, so nothing
     * would ever fix it. At boot, while the clock is still UTC and the setting
     * names another zone: use it from now on, and write it into .env once.
     * After that the clock is no longer UTC and this asks nothing.
     */
    public static function reconcile(): void
    {
        if (config('app.timezone') !== 'UTC') {
            return;
        }

        try {
            $zone = (string) setting('core', 'timezone', '');
        } catch (Throwable) {
            return; // no settings table yet: an install in progress
        }

        if ($zone === '' || $zone === 'UTC' || ! in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            return;
        }

        config(['app.timezone' => $zone]);
        date_default_timezone_set($zone);

        try {
            \Illuminate\Support\Facades\DB::statement('set time zone '.\Illuminate\Support\Facades\DB::getPdo()->quote($zone));
        } catch (Throwable) {
        }

        self::write($zone);
    }

    public static function write(string $zone): void
    {
        $zone = in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';

        // The test suite's settings must never rewrite the developer's .env.
        if (app()->runningUnitTests()) {
            return;
        }

        $env = app(EnvFile::class);

        if (! $env->writable() || $env->get('APP_TIMEZONE') === $zone) {
            return;
        }

        try {
            $env->set(['APP_TIMEZONE' => $zone]);
            // A cached config would go on saying the old zone. The file, not
            // `Artisan::call('config:clear')`: this runs while the application
            // BOOTS (reconcile), and calling Artisan from a provider's boot
            // makes the console application before the engine's commands are
            // registered — `torgash:update-finish` then "did not exist", and
            // the first live shop's update to 0.2.2 rolled itself back.
            $cached = app()->getCachedConfigPath();
            if (is_file($cached)) {
                @unlink($cached);
            }
        } catch (Throwable $e) {
            report($e);

            return;
        }

        // A worker booted with the old zone keeps it.
        WebWorkers::restart();
    }
}
