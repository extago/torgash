<?php

namespace Tests\Feature\Core;

use App\Core\Backups\Archive\Cipher;
use App\Core\Backups\Archive\CipherOutput;
use App\Core\Backups\Archive\DecipherInput;
use App\Core\Backups\Archive\FileInput;
use App\Core\Backups\Archive\FileOutput;
use App\Core\Backups\Archive\Reader;
use App\Core\Backups\Archive\VolumeOutput;
use App\Core\Backups\Backups;
use App\Core\Backups\Destinations;
use App\Core\Backups\Volumes;
use App\Models\BackupCopy;
use App\Models\BackupDestination;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A backup cut into volumes (PLAN §67.12): written as it goes — tar, gzip,
 * the cipher, the volumes — each volume stored everywhere and deleted
 * before the next is begun, so a site bigger than the free disk is backed
 * up all the same; read back the same way, volume by volume, each compared
 * with the journal. The volumes put together are an ordinary tar.gz.
 */
class BackupVolumesTest extends TestCase
{
    protected const DATABASE = 'torgash_volumes_check';

    protected string $work;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Backups::toolVersion('pg_dump') || ! Backups::toolVersion('pg_restore')) {
            $this->markTestSkipped('pg_dump / pg_restore are not available on this machine.');
        }

        Storage::fake('local');
        $this->work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'torgash-volumes-'.uniqid();
        File::ensureDirectoryExists($this->work.'/modules/acme.thing');
        file_put_contents($this->work.'/modules/acme.thing/manifest.json', '{"id":"acme.thing"}');
        // Something that does not compress, so the code alone takes volumes.
        file_put_contents($this->work.'/modules/acme.thing/noise.bin', random_bytes(300 * 1024));
        File::ensureDirectoryExists($this->work.'/themes');
        config(['torgash.modules_path' => $this->work.'/modules', 'torgash.themes_path' => $this->work.'/themes']);

        // A "megabyte" of a kilobyte: a small shop in many volumes.
        $backups = app(Backups::class);
        $backups->megabyte = 1024;
        $backups->retryPause = 0;
        $this->app->instance(Backups::class, $backups);
    }

    protected function tearDown(): void
    {
        $this->superuser(fn ($pdo) => $pdo->exec('drop database if exists '.self::DATABASE.' with (force)'));
        File::deleteDirectory($this->work);

        parent::tearDown();
    }

    public function test_volumes_are_stored_one_at_a_time_and_restore_as_one_backup(): void
    {
        $plan = $this->plan('tar.gz', 128);
        $leftOnDisk = [];
        $run = $this->makeRun($plan, function (string $message) use (&$leftOnDisk) {
            if ($message === 'Volume :n stored') {
                $leftOnDisk[] = count(glob(storage_path('app/backup-tmp/*/*.tar.gz.[0-9][0-9][0-9]')) ?: []);
            }
        });

        $this->assertSame(BackupRun::DONE, $run->status, (string) $run->error);
        $this->assertTrue($run->isVolumes());
        $this->assertGreaterThan(2, $run->volumeCount(), 'a small shop in kilobyte volumes is many of them');
        $this->assertSame($run->volumeCount(), count($leftOnDisk));
        $this->assertSame([0], array_values(array_unique($leftOnDisk)), 'a stored volume is off the disk before the next one is begun');

        $dir = Storage::disk('local')->path(Backups::DIR);
        $this->assertFileDoesNotExist($dir.'/'.$run->name, 'no whole file anywhere');
        $this->assertFileExists($dir.'/'.$run->name.Volumes::SET);
        foreach ((array) $run->volumes as $i => $volume) {
            $file = Volumes::file($dir.'/'.$run->name, $i + 1);
            $this->assertFileExists($file);
            $this->assertSame($volume['sha256'], hash_file('sha256', $file));
            $i + 1 < $run->volumeCount()
                ? $this->assertSame(128 * 1024, filesize($file), 'every volume but the last is full')
                : $this->assertLessThanOrEqual(128 * 1024, filesize($file));
        }

        // The join command the screens print, `cat <name>.* > <name>`, takes
        // the volumes and nothing else: the list beside them is outside the
        // glob (with a dot it was appended, and an encrypted set broke).
        $glob = glob($dir.'/'.$run->name.'.*') ?: [];
        sort($glob);
        $this->assertSame(array_map(fn (int $n) => Volumes::file($dir.'/'.$run->name, $n), range(1, $run->volumeCount())), $glob);
        $this->assertStringContainsString('"join": "cat '.$run->name.'.* > '.$run->name.'"', (string) file_get_contents($dir.'/'.$run->name.Volumes::SET));

        // Put together, they are one ordinary tar.gz — what `cat` makes.
        $joined = $this->join($dir.'/'.$run->name, $run->volumeCount());
        $this->assertSame($run->sha256, hash_file('sha256', $joined));
        $this->assertSame((int) $run->bytes, filesize($joined));
        $names = $this->entries($joined);
        $this->assertSame('manifest.json', $names[0]);
        $this->assertContains('database.dump', $names);
        $this->assertContains('code/modules/acme.thing/noise.bin', $names);

        // Restored by name: fetched volume by volume, each against the journal.
        $this->freshDatabase();
        $manifest = app(Backups::class)->restore($run->name, self::DATABASE, false);
        $this->assertSame('tar.gz', $manifest['format']);
        $this->assertGreaterThan(50, $this->tables());

        // On a new server: by the path of any of its files on this disk.
        foreach ([Volumes::file($dir.'/'.$run->name, 1), $dir.'/'.$run->name.Volumes::SET, $dir.'/'.$run->name] as $file) {
            $this->freshDatabase();
            app(Backups::class)->restore(basename($file), self::DATABASE, false, null, false, $file);
            $this->assertGreaterThan(50, $this->tables(), $file);
        }

        // A volume missing is said by its number.
        $moved = Volumes::file($dir.'/'.$run->name, 2);
        rename($moved, $moved.'.away');
        $this->assertThrowsMessage('Volume 2 of', fn () => app(Backups::class)->restore($run->name, self::DATABASE, false, null, false, $dir.'/'.$run->name.Volumes::SET));
        rename($moved.'.away', $moved);
    }

    public function test_encrypted_volumes_open_with_openssl_once_put_together(): void
    {
        $plan = $this->plan('tar.gz', 64, ['encrypt' => true, 'env' => true], 'correct horse battery');
        $run = $this->makeRun($plan);

        $this->assertSame(BackupRun::DONE, $run->status, (string) $run->error);
        $this->assertStringEndsWith('.tar.gz.enc', (string) $run->name);
        $dir = Storage::disk('local')->path(Backups::DIR);
        $this->assertStringNotContainsString('acme.thing', (string) file_get_contents(Volumes::file($dir.'/'.$run->name, 1)));

        $joined = $this->join($dir.'/'.$run->name, $run->volumeCount());
        Cipher::decryptFile($joined, $this->work.'/plain.tar.gz', 'correct horse battery');
        $this->assertSame('manifest.json', $this->entries($this->work.'/plain.tar.gz')[0]);

        // From the files alone the password is asked for, and a wrong one is said.
        $first = Volumes::file($dir.'/'.$run->name, 1);
        $this->assertThrowsMessage('give its password', fn () => app(Backups::class)->restore('x', self::DATABASE, false, null, false, $first));
        $this->assertThrowsMessage('password', fn () => app(Backups::class)->restore('x', self::DATABASE, false, null, false, $first, 'not it'));

        // The installer reads the head first, .env included, from the volumes alone.
        $head = app(Backups::class)->inspect($first, 'correct horse battery', $this->work.'/found.env');
        $this->assertTrue($head['env']);
        $this->assertStringContainsString('APP_KEY=', (string) file_get_contents($this->work.'/found.env'));
        $this->assertSame('tar.gz', $head['manifest']['format']);

        $this->freshDatabase();
        $manifest = app(Backups::class)->restore('x', self::DATABASE, false, null, false, $first, 'correct horse battery');
        $this->assertTrue($manifest['env_in_backup']);
        $this->assertGreaterThan(50, $this->tables());

        // By name the journal's plan gives the password.
        $this->freshDatabase();
        app(Backups::class)->restore($run->name, self::DATABASE, false);
        $this->assertGreaterThan(50, $this->tables());
    }

    public function test_a_volume_changed_where_it_is_kept_is_refused_before_it_is_read(): void
    {
        $run = $this->makeRun($this->plan('tar', 128));
        $this->assertSame(BackupRun::DONE, $run->status, (string) $run->error);

        $second = Volumes::file(Storage::disk('local')->path(Backups::DIR.'/'.$run->name), 2);
        $bytes = (string) file_get_contents($second);
        $bytes[100] = $bytes[100] === 'x' ? 'y' : 'x';
        file_put_contents($second, $bytes);

        $this->freshDatabase();
        $this->assertThrowsMessage('Volume 2 of the backup '.$run->name.' is not the file that was made', fn () => app(Backups::class)->restore($run->name, self::DATABASE, false));
    }

    public function test_a_storage_that_refused_a_volume_holds_nothing_and_is_sent_the_set_again(): void
    {
        $wall = $this->work.'/wall';
        file_put_contents($wall, 'a file where the folder should be');
        $shelf = BackupDestination::create(['name' => 'Shelf', 'driver' => 'folder', 'path_template' => '', 'settings' => ['path' => $wall]]);
        $plan = $this->plan('tar.gz', 128, [], '', 1);
        $plan->destinations()->attach($shelf->id, ['keep' => json_encode(['last' => 1])]);

        $run = $this->makeRun($plan);
        $this->assertSame(BackupRun::PARTIAL, $run->status, (string) $run->error);
        $failed = $run->copies()->where('status', BackupCopy::FAILED)->firstOrFail();
        $this->assertStringContainsString('Volume 1', (string) $failed->error, 'it is said which volume was refused');

        // The wall comes down: the set is sent from the server's copy, volume by volume.
        unlink($wall);
        $copy = app(Backups::class)->resend($failed);
        $this->assertSame(BackupCopy::STORED, $copy->status);
        $this->assertSame(BackupRun::DONE, $run->fresh()->status);
        for ($n = 1; $n <= $run->volumeCount(); $n++) {
            $this->assertSame($run->volumes[$n - 1]['sha256'], hash_file('sha256', Volumes::file($wall.'/'.$run->name, $n)));
        }
        $this->assertFileExists($wall.'/'.$run->name.Volumes::SET);
        $this->assertSame([], glob(storage_path('app/backup-tmp/*/volume')) ?: []);

        // The storage itself lists it, with no journal to ask.
        $listed = app(Backups::class)->listIn($shelf)->firstWhere('name', $run->name);
        $this->assertNotNull($listed);
        $this->assertSame($run->volumeCount(), $listed['volumes']);
        $this->assertSame((int) $run->bytes, $listed['bytes']);
        $this->freshDatabase();
        app(Backups::class)->restore($run->name, self::DATABASE, false, null, false, null, null, false, $shelf);
        $this->assertGreaterThan(50, $this->tables());

        // Keeping one: the next backup takes every volume and the set file of this one away.
        $next = $this->makeRun($plan, null, '2026-10-02 03:40:00');
        $this->assertSame(BackupRun::DONE, $next->status, (string) $next->error);
        $this->assertFalse(BackupRun::whereKey($run->id)->exists());
        $this->assertSame([], glob($wall.'/'.$run->name.'*') ?: []);
        $this->assertSame([], glob(Storage::disk('local')->path(Backups::DIR.'/'.$run->name).'*') ?: []);

        // Downloaded one volume at a time.
        $admin = User::where('email', 'admin@torgash.local')->firstOrFail();
        $stored = $next->storedCopies()->firstOrFail();
        $this->actingAs($admin)->get('http://localhost/admin/system/backups')->assertOk()
            ->assertSee(route('admin.system.backups.download', ['copy' => $stored, 'volume' => 2]), false);
        $this->get(route('admin.system.backups.download', $stored))->assertNotFound();
        $this->get(route('admin.system.backups.download', ['copy' => $stored, 'volume' => $next->volumeCount() + 1]))->assertNotFound();
        $response = $this->get(route('admin.system.backups.download', ['copy' => $stored, 'volume' => 2]));
        $response->assertOk();
        $this->assertSame($next->volumes[1]['sha256'], hash('sha256', $response->streamedContent()));
    }

    public function test_a_backup_the_disk_cannot_hold_is_refused_before_packing_and_volumes_are_offered(): void
    {
        $tight = new class(app(Destinations::class)) extends Backups
        {
            protected function freeSpace(string $dir): ?float
            {
                return 64.0 * 1048576 + 100 * 1024; // the margin and a hundred kilobytes
            }
        };
        $tight->megabyte = 1024;
        $tight->retryPause = 0;

        $whole = $tight->runNow($this->plan('tar.gz', 0));
        $this->assertSame(BackupRun::FAILED, $whole->status);
        $this->assertStringContainsString('Cut it into volumes', (string) $whole->error);

        // A kilobyte volume fits where the whole file did not (the code is 300 KB of noise).
        $tight->megabyte = 1;
        $cut = $tight->runNow($this->plan('tar.gz', 64 * 1024));
        $this->assertSame(BackupRun::DONE, $cut->status, (string) $cut->error);

        // And a volume bigger than the room is refused as well.
        $tight->megabyte = 1048576;
        $big = $tight->runNow($this->plan('tar', 1024));
        $this->assertSame(BackupRun::FAILED, $big->status);
        $this->assertStringContainsString('Make the volumes smaller', (string) $big->error);
    }

    public function test_the_plan_form_keeps_a_volume_size_for_a_tar_only(): void
    {
        $admin = User::where('email', 'admin@torgash.local')->firstOrFail();
        $server = app(Backups::class)->systemDestination();
        $fields = fn (string $format, array $options) => [
            'name' => 'Cut '.$format, 'name_template' => '{plan}-{date}-{time}', 'contents' => ['database'], 'schedule' => [],
            'destinations' => [$server->id => ['on' => '1', 'keep' => ['last' => 3]]],
            'format' => $format, 'options' => $options + ['level' => 'normal', 'db_format' => 'custom'],
        ];

        $this->actingAs($admin)->post(route('admin.system.backup-plans.store'), $fields('tar.gz', ['volume' => '512']))->assertSessionHasNoErrors();
        $plan = BackupPlan::where('name', 'Cut tar.gz')->firstOrFail();
        $this->assertSame(512, $plan->option('volume_mb'));
        $this->assertSame(512 * 1048576, $plan->volumeBytes());

        $this->put(route('admin.system.backup-plans.update', $plan), $fields('tar.gz', ['volume' => 'custom', 'volume_mb' => '300']))->assertSessionHasNoErrors();
        $this->assertSame(300, $plan->fresh()->option('volume_mb'));
        $this->get(route('admin.system.backup-plans.edit', $plan))->assertOk()->assertSee('name="options[volume_mb]"', false)->assertSee('value="300"', false);

        $this->put(route('admin.system.backup-plans.update', $plan), $fields('tar.gz', ['volume' => 'custom', 'volume_mb' => '2']))->assertSessionHasErrors('options.volume_mb');
        $this->put(route('admin.system.backup-plans.update', $plan), $fields('tar.gz', ['volume' => '']))->assertSessionHasNoErrors();
        $this->assertNull($plan->fresh()->option('volume_mb'));

        // A zip is finished only when it is closed: never cut.
        $this->put(route('admin.system.backup-plans.update', $plan), $fields('zip', ['volume' => '1024']))->assertSessionHasNoErrors();
        $this->assertNull($plan->fresh()->option('volume_mb'));
        $zip = $plan->fresh();
        $zip->options = ['volume_mb' => 1024] + (array) $zip->options;
        $this->assertNull($zip->volumeBytes());
    }

    /** The stages against the formats they claim, at sizes that end on and off a block's edge. */
    public function test_the_streamed_cipher_and_volumes_are_the_file_written_whole(): void
    {
        foreach ([0, 15, 16, 1048576, 1048576 * 2 + 7] as $size) {
            $body = $size > 0 ? random_bytes($size) : '';
            $volumes = [];
            $cut = new VolumeOutput($this->work.'/v', 100000, function (int $n, string $file, int $bytes, string $sha) use (&$volumes) {
                $volumes[$n] = file_get_contents($file);
                $this->assertSame($sha, hash('sha256', $volumes[$n]));
                $this->assertSame($bytes, strlen($volumes[$n]));
                unlink($file);
            });
            $out = new CipherOutput($cut, 'pw');
            foreach (str_split($body === '' ? '' : $body, 70001) as $piece) {
                $out->write($piece);
            }
            $out->close();

            file_put_contents($this->work.'/joined', implode('', $volumes));
            $this->assertSame($cut->sha256(), hash_file('sha256', $this->work.'/joined'), "$size bytes");
            Cipher::decryptFile($this->work.'/joined', $this->work.'/back', 'pw');
            $this->assertSame($body, (string) file_get_contents($this->work.'/back'), "$size bytes: openssl's form");

            // And what Cipher writes whole is read back as a stream.
            file_put_contents($this->work.'/plain', $body);
            Cipher::encryptFile($this->work.'/plain', $this->work.'/sealed', 'pw');
            $in = new DecipherInput(new FileInput($this->work.'/sealed'), 'pw');
            $read = '';
            while (($chunk = $in->read(50000)) !== '') {
                $read .= $chunk;
            }
            $in->close();
            $this->assertSame($body, $read, "$size bytes read as a stream");
        }

        $file = new FileOutput($this->work.'/f');
        $file->write('x');
        $file->close();
        $this->assertSame('x', file_get_contents($this->work.'/f'));
    }

    // ------------------------------------------------------------------

    protected function plan(string $format, int $volumeMb, array $options = [], string $password = '', int $keep = 5): BackupPlan
    {
        static $n = 0;
        $n++;

        $plan = new BackupPlan([
            'name' => 'Cut '.$n, 'code' => 'cut'.$n, 'contents' => ['database', 'code'], 'schedule' => [],
            'name_template' => 'v'.$n.'-{date}-{time}',
        ]);
        $plan->format = $format;
        $plan->options = $options + ['db_format' => 'custom', 'level' => 'fast'] + ($volumeMb > 0 ? ['volume_mb' => $volumeMb] : []);
        $plan->putPassword($password);
        $plan->save();
        $plan->destinations()->attach(app(Backups::class)->systemDestination()->id, ['keep' => json_encode(['last' => $keep])]);

        return $plan;
    }

    protected function makeRun(BackupPlan $plan, ?callable $say = null, string $at = '2026-10-01 03:40:00'): BackupRun
    {
        Carbon::setTestNow($at);

        try {
            return app(Backups::class)->runNow($plan, 'console', null, $say);
        } finally {
            Carbon::setTestNow();
        }
    }

    protected function join(string $base, int $count): string
    {
        $joined = $this->work.'/joined-'.basename($base);
        $out = fopen($joined, 'wb');
        for ($n = 1; $n <= $count; $n++) {
            fwrite($out, (string) file_get_contents(Volumes::file($base, $n)));
        }
        fclose($out);

        return $joined;
    }

    /** @return list<string> */
    protected function entries(string $file): array
    {
        $names = [];
        Reader::file($file)->each(function (string $name) use (&$names) {
            $names[] = $name;
        });

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
