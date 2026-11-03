<?php

namespace Tests\Feature\Core;

use App\Core\Backups\Backups;
use App\Core\Install\Installer;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * The installer's other door (PLAN §67.8, stage 6): a shop raised from a
 * backup. What can be checked inside the suite is everything that happens
 * BEFORE anything is written — the backup's head read, the refusals, the
 * steps — and the wizard's pages. The whole restore was drilled on a fresh
 * copy of the code into a database of its own, by the console and by the
 * wizard (docs/progress, 2026-11-03).
 */
class InstallRestoreTest extends TestCase
{
    protected const EMPTY_DB = 'torgash_restore_check_absent';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Backups::toolVersion('pg_dump') || ! Backups::toolVersion('pg_restore')) {
            $this->markTestSkipped('pg_dump / pg_restore are not available on this machine.');
        }

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        // begin() writes the marker and the staged .env into the REAL storage.
        @unlink(storage_path(Installer::INSTALLING));
        @unlink(storage_path(Installer::RESTORE_ENV));

        parent::tearDown();
    }

    public function test_the_head_of_a_backup_is_read_without_unpacking_it(): void
    {
        $file = $this->backup('Secret-password-1', env: true);
        $backups = app(Backups::class);
        $envTo = storage_path('app/inspect-test.env');

        try {
            $head = $backups->inspect($file, 'Secret-password-1', $envTo);
            $this->assertSame(config('torgash.version'), $head['manifest']['engine']);
            $this->assertTrue($head['env']);
            $this->assertStringContainsString('APP_KEY=', (string) file_get_contents($envTo));
            $this->assertTrue($backups->keyFits($head['manifest'], (string) config('app.key')));
            $this->assertFalse($backups->keyFits($head['manifest'], 'base64:'.base64_encode(random_bytes(32))));
        } finally {
            @unlink($envTo);
        }

        $this->assertThrowsMessage('password', fn () => $backups->inspect($file, 'Not-the-password'));
    }

    public function test_a_restore_is_refused_before_anything_is_written(): void
    {
        $file = $this->backup('Secret-password-1', env: true);

        $this->assertThrowsMessage('There is no backup at', fn () => $this->begin(['file' => $file.'.missing', 'password' => 'Secret-password-1']));
        $this->assertThrowsMessage('APP_KEY', fn () => $this->begin(['file' => $file, 'password' => 'Secret-password-1']), 'no key at all');
        $this->assertThrowsMessage('not the one the backup was made with', fn () => $this->begin(['file' => $file, 'password' => 'Secret-password-1', 'app_key' => 'base64:'.base64_encode(random_bytes(32))]));
        $this->assertThrowsMessage('not an application key', fn () => $this->begin(['file' => $file, 'password' => 'Secret-password-1', 'app_key' => 'hunter2']));
        $this->assertThrowsMessage('already has', fn () => $this->begin(['file' => $file, 'password' => 'Secret-password-1', 'with_env' => true], (string) config('database.connections.pgsql.database')), 'into a database that has tables');

        $this->assertFileDoesNotExist(storage_path(Installer::INSTALLING), 'a refusal starts no install');
        $this->assertFileDoesNotExist(storage_path(Installer::RESTORE_ENV), 'nor leaves the old shop\'s .env behind');
    }

    public function test_a_backup_of_a_newer_engine_is_refused(): void
    {
        $file = $this->backup();
        $zip = new \ZipArchive;
        $zip->open($file);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $manifest['engine'] = '99.0.0';
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();

        $this->assertThrowsMessage('Torgash 99.0.0', fn () => $this->begin(['file' => $file, 'app_key' => (string) config('app.key')]));
    }

    public function test_an_accepted_restore_has_its_own_steps_and_keeps_the_key(): void
    {
        $file = $this->backup('Secret-password-1', env: true);
        $installer = new Installer(storage_path('app/never-written.env'));

        $answers = $installer->begin([
            'url' => '', 'db' => $this->db(self::EMPTY_DB), 'styles' => false,
            'restore' => ['file' => $file, 'password' => 'Secret-password-1', 'with_env' => true],
        ]);

        $this->assertSame(['env', 'database', 'restore', 'upgrade', 'settle', 'lock'], array_column($installer->steps($answers), 'key'));
        $this->assertSame(rtrim((string) config('app.url'), '/'), $answers['url'], 'no address typed: the one the shop had');
        $this->assertFalse($answers['restore']['new_key']);
        $this->assertFileExists(storage_path(Installer::RESTORE_ENV), 'the backup\'s .env waits for the step that writes it');
        $this->assertSame($answers['restore']['file'], Installer::pending()['restore']['file'], 'the next request reads it from the marker');

        // Typed instead, without base64: — and a new key, said to be new.
        @unlink(storage_path(Installer::INSTALLING));
        $typed = $installer->begin(['db' => $this->db(self::EMPTY_DB), 'restore' => ['file' => $file, 'password' => 'Secret-password-1', 'app_key' => substr((string) config('app.key'), 7)]]);
        $this->assertSame((string) config('app.key'), $typed['restore']['app_key']);

        @unlink(storage_path(Installer::INSTALLING));
        $fresh = $installer->begin(['db' => $this->db(self::EMPTY_DB), 'restore' => ['file' => $file, 'password' => 'Secret-password-1', 'new_key' => true]]);
        $this->assertTrue($fresh['restore']['new_key']);
        $this->assertNotSame((string) config('app.key'), $fresh['restore']['app_key']);
    }

    public function test_the_wizard_draws_both_doors(): void
    {
        $data = ['server' => [], 'keyPath' => 'storage/app/install-key', 'url' => 'https://shop.test', 'pending' => false, 'bundles' => ['store' => 30], 'found' => ['/srv/a.zip' => 'a.zip · 1.0 MB'], 'uploadLimit' => '64 MB'];

        $new = view('install.page', $data + ['mode' => 'new'])->render();
        $this->assertStringContainsString('name="owner_email"', $new);
        $this->assertStringNotContainsString('name="backup_path"', $new);

        $restore = view('install.page', $data + ['mode' => 'restore'])->render();
        foreach (['name="backup_found"', 'name="backup_path"', 'name="backup_file"', 'name="key_from"', 'name="app_key"', 'name="pg_bin"', 'multipart/form-data'] as $part) {
            $this->assertStringContainsString($part, $restore);
        }
        $this->assertStringNotContainsString('name="owner_email"', $restore, 'a restored shop has its owner already');

        $answers = ['url' => 'https://shop.test', 'restore' => ['new_key' => true, 'missing_modules' => ['acme.paid'], 'manifest' => ['created_at' => '2026-11-03', 'engine' => '0.3.12']]];
        $done = view('install.restored', ['answers' => $answers])->render();
        $this->assertStringContainsString('acme.paid', $done);
        $this->assertStringContainsString('NEW key', $done);
    }

    protected function begin(array $restore, string $database = self::EMPTY_DB): array
    {
        return (new Installer(storage_path('app/never-written.env')))->begin(['db' => $this->db($database), 'restore' => $restore]);
    }

    /** @return array{host: string, port: int, database: string, username: string, password: string} */
    protected function db(string $database): array
    {
        $c = config('database.connections.pgsql');

        return ['host' => $c['host'], 'port' => (int) $c['port'], 'database' => $database, 'username' => $c['username'], 'password' => (string) $c['password']];
    }

    protected function backup(string $password = '', bool $env = false): string
    {
        static $n = 0;
        $n++;

        $plan = new BackupPlan(['name' => 'Raise '.$n, 'code' => 'raise'.$n, 'contents' => ['database'], 'schedule' => [], 'name_template' => 'r'.$n.'-{date}-{time}']);
        $plan->format = 'zip';
        $plan->options = ['db_format' => 'custom', 'level' => 'fast', 'encrypt' => $password !== '', 'env' => $env];
        $plan->putPassword($password);
        $plan->save();
        $plan->destinations()->attach(app(Backups::class)->systemDestination()->id, ['keep' => json_encode(['last' => 5])]);

        $run = app(Backups::class)->runNow($plan);
        $this->assertSame(BackupRun::DONE, $run->status, (string) $run->error);

        return Storage::disk('local')->path(Backups::DIR.'/'.$run->name);
    }

    protected function assertThrowsMessage(string $part, \Closure $do, string $why = ''): void
    {
        try {
            $do();
            $this->fail("Expected an error saying \"$part\". $why");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($part, $e->getMessage(), $why);
        }
    }
}
