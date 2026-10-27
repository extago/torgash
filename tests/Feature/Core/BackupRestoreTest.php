<?php

namespace Tests\Feature\Core;

use App\Core\Backups\Backups;
use App\Core\Settings\CoreSettings;
use App\Core\Settings\SettingsManager;
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
        app(SettingsManager::class)->set(CoreSettings::ID, 'backup_disk', 'local');
        $name = app(Backups::class)->create(withFiles: false);

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
            app(Backups::class)->restore($name, null, false);
        } finally {
            config(['database.connections.pgsql' => $connection]);
        }

        $restored = $this->superuser(
            fn ($pdo) => (int) $pdo->query("select count(*) from information_schema.tables where table_schema = 'public'")->fetchColumn(),
            self::DATABASE,
        );

        $this->assertGreaterThan(50, $restored, 'the shop\'s tables are back');
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
