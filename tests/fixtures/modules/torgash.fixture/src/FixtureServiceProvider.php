<?php

namespace Tests\Fixtures;

use App\Core\Modules\ModuleLifecycle;
use Illuminate\Support\ServiceProvider;

/**
 * The fixture module's provider, which exists so the lifecycle tests have a
 * module that does something of its own on install — the shape torgash.commerceml
 * uses to put `/1c_exchange.php` in place.
 *
 * It writes and removes a file, because that is the case the interface was
 * added for: work outside the database, which migrations cannot undo.
 */
class FixtureServiceProvider extends ServiceProvider implements ModuleLifecycle
{
    public static function marker(): string
    {
        return storage_path('framework/testing/fixture-module-was-installed.txt');
    }

    public function install(): void
    {
        @mkdir(dirname(self::marker()), 0755, true);
        file_put_contents(self::marker(), 'installed');
    }

    public function uninstall(): void
    {
        if (is_file(self::marker())) {
            unlink(self::marker());
        }
    }
}
