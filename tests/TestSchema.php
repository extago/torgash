<?php

namespace Tests;

use App\Core\Modules\ModuleManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Builds the test database once per run.
 *
 * The schema cannot come from `migrate` alone: half of it ships inside modules
 * and only reaches the database through ModuleManager::install(), which also
 * registers the module and its permissions. So the build is migrate + install
 * every module on disk + seed, and tests then run inside a transaction that is
 * rolled back, which is far cheaper than rebuilding per test.
 *
 * A fingerprint of every migration and manifest decides when to rebuild, so
 * adding a migration is picked up automatically and an unchanged suite pays
 * nothing.
 */
class TestSchema
{
    private static bool $checked = false;

    /** @return bool whether the schema was actually (re)built by this call */
    public static function ensure(): bool
    {
        if (self::$checked) {
            return false;
        }

        self::$checked = true;

        $database = config('database.connections.pgsql.database');

        // The dev database and the test database differ by one line in
        // phpunit.xml, and the first thing done below is migrate:fresh.
        if (! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException(
                "Refusing to build a test schema in [{$database}]: the test database name must end in _test."
            );
        }

        self::createDatabaseIfMissing($database);

        $marker = storage_path('framework/testing/schema.fingerprint');
        $fingerprint = self::fingerprint();

        if (self::isBuilt() && is_file($marker) && trim(file_get_contents($marker)) === $fingerprint) {
            return false;
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
        self::installModules();
        Artisan::call('db:seed', ['--force' => true]);

        if (! is_dir(dirname($marker))) {
            mkdir(dirname($marker), 0777, true);
        }

        file_put_contents($marker, $fingerprint);

        return true;
    }

    /** Marks the schema as needing a rebuild before the next test process. */
    public static function forget(): void
    {
        @unlink(storage_path('framework/testing/schema.fingerprint'));
    }

    private static function isBuilt(): bool
    {
        return Schema::hasTable('modules') && DB::table('modules')->exists();
    }

    /**
     * Connects to the server's own `postgres` database to create ours, so a
     * fresh checkout needs no manual CREATE DATABASE before running the suite.
     *
     * The locale is not a detail. Under LC_CTYPE=C Postgres does not treat
     * Cyrillic as letters at all: lower() leaves it alone, ILIKE stops folding
     * case, to_tsvector keeps capitals as distinct lexemes and pg_trgm returns
     * NO trigrams whatsoever — so Russian search silently half-works. The root
     * ICU locale ('und') fixes all four without picking a language's sort
     * order. A test database with the wrong locale is dropped and rebuilt:
     * otherwise the suite would pass here and the shop would fail in Russian.
     */
    private static function createDatabaseIfMissing(string $database): void
    {
        config(['database.connections.pgsql_bootstrap' => array_merge(
            config('database.connections.pgsql'),
            ['database' => 'postgres'],
        )]);

        $existing = DB::connection('pgsql_bootstrap')->select(
            'select datctype, datlocprovider from pg_database where datname = ?',
            [$database],
        );

        if ($existing !== [] && ($existing[0]->datctype === 'C' || $existing[0]->datlocprovider === 'c')) {
            DB::connection('pgsql_bootstrap')->statement('drop database "'.$database.'" with (force)');
            $existing = [];
            self::forget();
        }

        if ($existing === []) {
            // The name is validated above and comes from config, not input.
            DB::connection('pgsql_bootstrap')->statement(
                'create database "'.$database.'" template template0 encoding \'UTF8\' '
                ."lc_collate 'und' lc_ctype 'und' locale_provider icu icu_locale 'und'",
            );
        }

        DB::purge('pgsql_bootstrap');
    }

    /**
     * Installs every module on disk, dependencies first. The order is worked
     * out by repetition rather than a topological sort: the set is small, and
     * this way a cycle stops the run with a readable list instead of hanging.
     */
    private static function installModules(): void
    {
        // A fresh instance, not the container's: that one read the modules
        // table while booting — before migrate:fresh dropped it — and would
        // refuse every install as "already installed" from that stale list.
        $manager = new ModuleManager(app());
        $pending = array_keys($manager->all());

        while ($pending !== []) {
            $installedSomething = false;

            foreach ($pending as $i => $id) {
                $requires = $manager->find($id)->requires['modules'] ?? [];

                foreach ($requires as $dependency) {
                    if (! $manager->isInstalled($dependency)) {
                        continue 2;
                    }
                }

                $manager->install($id);
                unset($pending[$i]);
                $installedSomething = true;
            }

            if (! $installedSomething) {
                throw new RuntimeException(
                    'Cannot install these modules — unmet or circular dependencies: '.implode(', ', $pending)
                );
            }
        }
    }

    /** Changes to any migration or manifest invalidate the built schema. */
    private static function fingerprint(): string
    {
        $files = array_merge(
            glob(database_path('migrations/*.php')) ?: [],
            glob(base_path('modules/*/migrations/*.php')) ?: [],
            glob(base_path('modules/*/manifest.json')) ?: [],
            [database_path('seeders/CoreSeeder.php')],
        );

        sort($files);

        return md5(implode('', array_map(
            fn (string $file) => $file.filesize($file).md5_file($file),
            array_filter($files, 'is_file'),
        )));
    }
}
