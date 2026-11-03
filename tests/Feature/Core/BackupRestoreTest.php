<?php

namespace Tests\Feature\Core;

use App\Core\Backups\Backups;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A backup restores as the shop's own database user — the one a real server
 * runs it as, and the one an update's rollback depends on.
 *
 * On the first live shop the extension pg_trgm belonged to `postgres`, the
 * shop's user could not drop it, `--clean` began by trying, and the whole
 * restore was refused (2026-10-22). Here the same: a role that owns a
 * database but not its extension, and the shop's backup restored into it.
 */
class BackupRestoreTest extends TestCase
{
    protected const ROLE = 'torgash_restore_role';

    protected const DATABASE = 'torgash_restore_check';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Backups::toolVersion('pg_dump') || ! Backups::toolVersion('pg_restore')) {
            $this->markTestSkipped('pg_dump / pg_restore are not available on this machine.');
        }
    }

    protected function tearDown(): void
    {
        $this->superuser(fn ($pdo) => $pdo->exec('drop database if exists '.self::DATABASE.' with (force)'));
        $this->superuser(fn ($pdo) => $pdo->exec('drop role if exists '.self::ROLE));

        parent::tearDown();
    }

    public function test_a_backup_restores_as_a_user_that_does_not_own_the_extensions(): void
    {
        Storage::fake('local');
        $name = app(Backups::class)->forUpdate();

        $this->superuser(function ($pdo) {
            $pdo->exec('drop database if exists '.self::DATABASE.' with (force)');
            $pdo->exec('drop role if exists '.self::ROLE);
            $pdo->exec('create role '.self::ROLE." login password 'x'");
            $pdo->exec('create database '.self::DATABASE.' owner '.self::ROLE." template template0 encoding 'UTF8' lc_collate 'und' lc_ctype 'und' locale_provider icu icu_locale 'und'");
        });
        // The extension made by the superuser, as on a real server.
        $this->superuser(fn ($pdo) => $pdo->exec('create extension pg_trgm'), self::DATABASE);

        $connection = config('database.connections.pgsql');
        config(['database.connections.pgsql.username' => self::ROLE, 'database.connections.pgsql.password' => 'x', 'database.connections.pgsql.database' => self::DATABASE]);

        try {
            $manifest = app(Backups::class)->restore($name, null, false);
        } finally {
            config(['database.connections.pgsql' => $connection]);
            \Illuminate\Support\Facades\DB::purge();
        }

        $restored = $this->superuser(
            fn ($pdo) => (int) $pdo->query("select count(*) from information_schema.tables where table_schema = 'public'")->fetchColumn(),
            self::DATABASE,
        );

        $this->assertGreaterThan(50, $restored, 'the shop\'s tables are back');
        $this->assertTrue($manifest['key_matches'], 'made and restored under the same APP_KEY');
        $this->assertTrue($manifest['cyrillic_ok']);
        // The run that was being made when the dump was taken is not left
        // "running" in the restored journal.
        $this->assertSame(0, $this->superuser(
            fn ($pdo) => (int) $pdo->query("select count(*) from backup_runs where status in ('queued', 'running')")->fetchColumn(),
            self::DATABASE,
        ));
    }

    /**
     * A new server after the old one is gone (the restore drill, 2026-11-03):
     * the database is EMPTY — no pg_trgm, which the search indexes need — and
     * the backup is a file named by its path, the journal knowing nothing.
     */
    public function test_a_backup_file_restores_into_an_empty_database(): void
    {
        Storage::fake('local');
        $name = app(Backups::class)->forUpdate();
        $file = Storage::disk('local')->path(Backups::DIR.'/'.$name);
        $copy = sys_get_temp_dir().DIRECTORY_SEPARATOR.'torgash-drill-'.uniqid().'.zip';
        copy($file, $copy);

        $this->superuser(function ($pdo) {
            $pdo->exec('drop database if exists '.self::DATABASE.' with (force)');
            $pdo->exec('create database '.self::DATABASE." template template0 encoding 'UTF8' lc_collate 'und' lc_ctype 'und' locale_provider icu icu_locale 'und'");
        });

        // The shop's OWN database is the empty one, as on the new server.
        $connection = config('database.connections.pgsql');
        config(['database.connections.pgsql.database' => self::DATABASE]);
        \Illuminate\Support\Facades\DB::purge();

        try {
            $manifest = app(Backups::class)->restore(basename($copy), null, false, null, false, $copy);
        } finally {
            config(['database.connections.pgsql' => $connection]);
            \Illuminate\Support\Facades\DB::purge();
            @unlink($copy);
        }

        $this->assertSame(['database'], $manifest['contents']);
        $this->assertTrue($this->superuser(
            fn ($pdo) => (bool) $pdo->query("select count(*) from pg_indexes where indexname = 'm_catalog_products_name_trgm_idx'")->fetchColumn(),
            self::DATABASE,
        ), 'the search indexes are there, so pg_trgm was created first');
    }

    public function test_another_app_key_is_said_out_loud(): void
    {
        $backups = app(Backups::class);
        $check = \Illuminate\Support\Facades\Crypt::encryptString(Backups::KEY_CHECK);
        $match = new \ReflectionMethod($backups, 'keyMatches');

        $this->assertTrue($match->invoke($backups, $check));
        $this->assertNull($match->invoke($backups, null), 'a backup older than the check');

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->app->forgetInstance('encrypter');
        \Illuminate\Support\Facades\Crypt::clearResolvedInstance('encrypter');

        $this->assertFalse($match->invoke($backups, $check));
    }

    /** A plain PDO as the suite's own (super)user — outside the test's transaction. */
    protected function superuser(\Closure $do, string $database = 'postgres'): mixed
    {
        $c = config('database.connections.pgsql');
        $pdo = new \PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$database}", $c['username'], (string) $c['password']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $do($pdo);
    }
}
