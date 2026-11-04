<?php

namespace Tests\Feature\Core;

use App\Core\Backups\Archive\Cipher;
use App\Core\Backups\Archive\Reader;
use App\Core\Backups\Archive\TarWriter;
use App\Core\Backups\Backups;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The forms a backup is made in (PLAN §67.3): zip, tar, tar.gz, a folder
 * without an archive; the database as a dump or plain SQL; encrypted with a
 * password; .env inside an encrypted one. Each is RESTORED here, into a
 * database of its own — a backup that cannot be restored is not one.
 */
class BackupFormatsTest extends TestCase
{
    protected const DATABASE = 'torgash_formats_check';

    protected string $code;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Backups::toolVersion('pg_dump') || ! Backups::toolVersion('pg_restore')) {
            $this->markTestSkipped('pg_dump / pg_restore are not available on this machine.');
        }

        Storage::fake('local');

        // The "code" part from a folder of its own, not the real modules.
        $this->code = sys_get_temp_dir().DIRECTORY_SEPARATOR.'torgash-formats-'.uniqid();
        File::ensureDirectoryExists($this->code.'/modules/acme.thing');
        file_put_contents($this->code.'/modules/acme.thing/manifest.json', '{"id":"acme.thing"}');
        File::ensureDirectoryExists($this->code.'/themes');
        config(['torgash.modules_path' => $this->code.'/modules', 'torgash.themes_path' => $this->code.'/themes']);
    }

    protected function tearDown(): void
    {
        $this->superuser(fn ($pdo) => $pdo->exec('drop database if exists '.self::DATABASE.' with (force)'));
        File::deleteDirectory($this->code);

        parent::tearDown();
    }

    public function test_every_form_is_made_and_restores(): void
    {
        $cases = [
            ['zip', 'plain', '.zip'],
            ['tar', 'custom', '.tar'],
            ['tar.gz', 'custom', '.tar.gz'],
            ['folder', 'plain', ''],
        ];

        foreach ($cases as [$format, $db, $extension]) {
            $run = $this->makeRun($this->plan($format, ['db_format' => $db]));

            $this->assertSame(BackupRun::DONE, $run->status, $format.': '.$run->error);
            $this->assertStringEndsWith('-034000'.$extension, $run->name.'', $format);
            $this->assertSame($format, $run->format);

            $where = Storage::disk('local')->path(Backups::DIR.'/'.$run->name);
            $format === 'folder'
                ? $this->assertFileExists($where.'/manifest.json', 'a folder, the manifest written last')
                : $this->assertFileExists($where);

            $names = $this->entries($format === 'folder' ? null : $where, $format === 'folder' ? $where : null);
            $this->assertSame('manifest.json', $names[0], $format.': the manifest comes first');
            $this->assertContains($db === 'plain' ? 'database.sql' : 'database.dump', $names, $format);
            $this->assertContains('code/modules/acme.thing/manifest.json', $names, $format);

            $this->freshDatabase();
            $manifest = app(Backups::class)->restore($run->name, self::DATABASE, false);

            $this->assertSame($format, $manifest['format']);
            $this->assertGreaterThan(50, $this->tables(), $format.'/'.$db.': the shop\'s tables are back');
        }
    }

    public function test_an_encrypted_backup_needs_its_password_and_the_journal_knows_it(): void
    {
        foreach (['zip' => '.zip', 'tar.gz' => '.tar.gz.enc'] as $format => $extension) {
            $plan = $this->plan($format, ['encrypt' => true, 'env' => true], 'correct horse battery');
            $run = $this->makeRun($plan);

            $this->assertSame(BackupRun::DONE, $run->status, $format.': '.$run->error);
            $this->assertTrue($run->encrypted);
            $this->assertStringEndsWith($extension, $run->name);

            $file = Storage::disk('local')->path(Backups::DIR.'/'.$run->name);
            $raw = (string) file_get_contents($file);
            $this->assertStringNotContainsString('APP_KEY=', $raw, $format.': .env is not readable without the password');
            // zip encrypts what is in the files, not their names; a tar is encrypted whole.
            $format === 'zip'
                ? $this->assertStringContainsString('acme.thing', $raw)
                : $this->assertStringNotContainsString('acme.thing', $raw, $format.': nor are the names inside');

            // A copy of the file on a "new server": no journal to give the password.
            $copy = sys_get_temp_dir().DIRECTORY_SEPARATOR.basename($file);
            copy($file, $copy);

            try {
                $this->assertThrowsMessage('give its password', fn () => app(Backups::class)->restore(basename($copy), self::DATABASE, false, null, false, $copy));
                $this->assertThrowsMessage('Wrong password', fn () => app(Backups::class)->restore(basename($copy), self::DATABASE, false, null, false, $copy, 'not it'));

                $this->freshDatabase();
                $manifest = app(Backups::class)->restore(basename($copy), self::DATABASE, false, null, false, $copy, 'correct horse battery');
                $this->assertTrue($manifest['env_in_backup'], $format.': .env inside');
                $this->assertFalse($manifest['env_written'], 'never put in place unless asked');
                $this->assertGreaterThan(50, $this->tables());
            } finally {
                @unlink($copy);
            }

            // Restored by name, the journal's plan gives the password.
            $this->freshDatabase();
            app(Backups::class)->restore($run->name, self::DATABASE, false);
            $this->assertGreaterThan(50, $this->tables());
        }

        $this->assertStringContainsString('-iter '.Cipher::ITERATIONS, Cipher::command());
    }

    public function test_keeping_n_deletes_a_folder_backup_whole(): void
    {
        $plan = $this->plan('folder', [], '', 1);
        $first = $this->makeRun($plan, '2026-10-01 03:40:00');
        $second = $this->makeRun($plan, '2026-10-02 03:40:00');

        $this->assertSame(BackupRun::DONE, $second->status, (string) $second->error);
        $this->assertFalse(BackupRun::whereKey($first->id)->exists());
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path(Backups::DIR.'/'.$first->name));
        $this->assertFileExists(Storage::disk('local')->path(Backups::DIR.'/'.$second->name.'/manifest.json'));

        // A folder is not one file to download; the list does not offer it.
        $admin = User::where('email', 'admin@torgash.local')->firstOrFail();
        $this->actingAs($admin)->get('http://localhost/admin/system/backups/copies/'.$second->storedCopies()->first()->id)->assertNotFound();
        app(Backups::class)->deleteRun($second);
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path(Backups::DIR.'/'.$second->name));
    }

    public function test_the_plan_form_keeps_the_password_encrypted_and_asks_for_it(): void
    {
        $admin = User::where('email', 'admin@torgash.local')->firstOrFail();
        $server = app(Backups::class)->systemDestination();
        $fields = [
            'name' => 'Secret', 'name_template' => '{plan}-{date}-{time}', 'contents' => ['database'], 'schedule' => [],
            'destinations' => [$server->id => ['on' => '1', 'keep' => ['last' => 3]]],
            'format' => 'tar.gz', 'options' => ['encrypt' => '1', 'env' => '1', 'level' => 'max', 'db_format' => 'plain'],
        ];

        $this->actingAs($admin)->post(route('admin.system.backup-plans.store'), $fields)->assertSessionHasErrors('password');
        $this->post(route('admin.system.backup-plans.store'), $fields + ['password' => 'long enough pass', 'password_confirmation' => 'other'])->assertSessionHasErrors('password');
        $this->post(route('admin.system.backup-plans.store'), $fields + ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post(route('admin.system.backup-plans.store'), $fields + ['password' => 'long enough pass', 'password_confirmation' => 'long enough pass'])->assertSessionHasNoErrors();

        $plan = BackupPlan::where('name', 'Secret')->firstOrFail();
        $this->assertSame('long enough pass', $plan->password());
        $this->assertStringNotContainsString('long enough pass', (string) $plan->getRawOriginal('secret'));
        // jsonb keeps no key order.
        $this->assertEqualsCanonicalizing(['db_format' => 'plain', 'level' => 'max', 'encrypt' => true, 'env' => true, 'verify' => '', 'changed_only' => false, 'without_thumbnails' => false], $plan->options);
        $this->assertSame('tar.gz.enc', $plan->extension());
        $this->assertSame(9, $plan->level());

        // A blank password keeps it; a folder is never encrypted, nor carries .env.
        $this->put(route('admin.system.backup-plans.update', $plan), $fields)->assertSessionHasNoErrors();
        $this->assertSame('long enough pass', $plan->fresh()->password());
        $this->put(route('admin.system.backup-plans.update', $plan), ['format' => 'folder'] + $fields)->assertSessionHasNoErrors();
        $this->assertFalse($plan->fresh()->encrypts());
        $this->assertFalse($plan->fresh()->option('env'));

        $this->get(route('admin.system.backup-plans.edit', $plan))->assertOk()
            ->assertDontSee('long enough pass')
            ->assertSee('name="options[encrypt]"', false);
    }

    /** The engine's own tar and cipher, against the formats they claim. */
    public function test_the_tar_and_the_cipher_round_trip(): void
    {
        $dir = $this->code;
        file_put_contents("$dir/body.bin", random_bytes(2 * 1048576)); // ends on a block's edge
        $long = 'files/local/'.str_repeat('a-long-folder/', 10).'picture.webp';

        $tar = new TarWriter("$dir/t.tar.gz", 6);
        $tar->addString('manifest.json', '{}');
        $tar->addFile('database.dump', "$dir/body.bin");
        $tar->addString($long, 'img');
        $tar->close();

        Cipher::encryptFile("$dir/t.tar.gz", "$dir/t.enc", 'pw');
        Cipher::decryptFile("$dir/t.enc", "$dir/back.tar.gz", 'pw');
        $this->assertFileEquals("$dir/t.tar.gz", "$dir/back.tar.gz");
        $this->assertThrowsMessage('Wrong password', fn () => Cipher::decryptFile("$dir/t.enc", "$dir/x", 'not it'));

        $read = [];
        Reader::file("$dir/back.tar.gz")->each(function (string $name, $stream) use (&$read) {
            $read[$name] = hash('sha256', (string) stream_get_contents($stream));
        });
        $this->assertSame(['manifest.json', 'database.dump', $long], array_keys($read));
        $this->assertSame(hash_file('sha256', "$dir/body.bin"), $read['database.dump']);
    }

    // ------------------------------------------------------------------

    protected function plan(string $format, array $options = [], string $password = '', int $keep = 5): BackupPlan
    {
        static $n = 0;
        $n++;

        $plan = new BackupPlan([
            'name' => 'Form '.$n, 'code' => 'form'.$n, 'contents' => ['database', 'code'], 'schedule' => [],
            'name_template' => 'f'.$n.'-{date}-{time}',
        ]);
        $plan->format = $format;
        $plan->options = $options + ['db_format' => 'custom', 'level' => 'fast'];
        $plan->putPassword($password);
        $plan->save();
        $plan->destinations()->attach(app(Backups::class)->systemDestination()->id, ['keep' => json_encode(['last' => $keep])]);

        return $plan;
    }

    protected function makeRun(BackupPlan $plan, string $at = '2026-10-01 03:40:00'): BackupRun
    {
        \Illuminate\Support\Carbon::setTestNow($at);

        try {
            return app(Backups::class)->runNow($plan);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    /** @return list<string> */
    protected function entries(?string $file, ?string $folder): array
    {
        $reader = $file !== null
            ? Reader::file($file)
            : new \App\Core\Backups\Archive\FolderReader(new \App\Core\Backups\Store(Storage::build(['driver' => 'local', 'root' => $folder])), '');
        $names = [];
        $reader->each(function (string $name) use (&$names) {
            $names[] = $name;
        });
        $reader->close();

        return $names;
    }

    protected function assertThrowsMessage(string $part, \Closure $do): void
    {
        try {
            $do();
            $this->fail("Expected an error saying \"$part\".");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($part, $e->getMessage());
        }
    }

    protected function freshDatabase(): void
    {
        $this->superuser(function ($pdo) {
            $pdo->exec('drop database if exists '.self::DATABASE.' with (force)');
            $pdo->exec('create database '.self::DATABASE." template template0 encoding 'UTF8' lc_collate 'und' lc_ctype 'und' locale_provider icu icu_locale 'und'");
        });
    }

    protected function tables(): int
    {
        return (int) $this->superuser(
            fn ($pdo) => $pdo->query("select count(*) from information_schema.tables where table_schema = 'public'")->fetchColumn(),
            self::DATABASE,
        );
    }

    protected function superuser(\Closure $do, string $database = 'postgres'): mixed
    {
        $c = config('database.connections.pgsql');
        $pdo = new \PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$database}", $c['username'], (string) $c['password']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $do($pdo);
    }
}
