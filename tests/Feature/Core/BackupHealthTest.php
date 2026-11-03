<?php

namespace Tests\Feature\Core;

use App\Core\Admin\AdminNotifications;
use App\Core\Backups\BackupHealth;
use App\Core\Backups\Backups;
use App\Core\Mail\CoreLetters;
use App\Core\Mail\EventMail;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PLAN §67, stage 4: a backup gone wrong is SAID — the bell, the System page,
 * a letter — and so is silence (a schedule with no fresh backup); a backup is
 * checked by restoring it; the console restores from a storage the journal
 * knows nothing about.
 */
class BackupHealthTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->admin = User::role('admin')->firstOrFail();
    }

    public function test_a_failed_backup_rings_the_bell_and_writes_a_letter(): void
    {
        Mail::fake();
        $plan = $this->plan(['schedule' => [['kind' => 'daily', 'at' => '03:40']]]);
        $this->journal($plan, BackupRun::DONE, now()->subHours(2));

        $this->assertCount(0, app(BackupHealth::class)->attention(), 'a fresh good backup: nothing to say');

        $run = BackupRun::create(['plan_id' => $plan->id, 'name' => 'x.zip', 'status' => BackupRun::QUEUED, 'reason' => 'manual', 'format' => 'zip']);
        $this->breakTheDump();
        app(Backups::class)->perform($run);

        $this->assertSame(BackupRun::FAILED, $run->fresh()->status);
        $attention = app(BackupHealth::class)->attention();
        $this->assertSame(['failed'], $attention->pluck('problem')->all());
        $this->assertStringContainsString($plan->name, $attention->first()['reason']);

        $this->actingAs($this->admin);
        $bell = collect(app(AdminNotifications::class)->all())->firstWhere('key', 'backups');
        $this->assertSame(1, (int) $bell['count']);

        $this->get('http://localhost/admin/system')->assertOk()->assertSee('The last backup by', false);

        Mail::assertQueued(EventMail::class, fn (EventMail $mail) => $mail->event === CoreLetters::BACKUP_PROBLEM && $mail->hasTo($this->admin->email));
    }

    public function test_silence_is_noticed_by_the_schedule(): void
    {
        $daily = $this->plan(['schedule' => [['kind' => 'daily', 'at' => '03:40']]]);
        $byHand = $this->plan(['schedule' => []]);
        $this->journal($daily, BackupRun::DONE, now()->subDays(3));
        $this->journal($byHand, BackupRun::DONE, now()->subYear());

        $this->assertSame(27, app(BackupHealth::class)->allowedHours($daily), 'a day between moments, plus three hours');
        $this->assertNull(app(BackupHealth::class)->allowedHours($byHand), 'a plan run by hand is never overdue');

        $attention = app(BackupHealth::class)->attention();
        $this->assertSame([$daily->id], $attention->map(fn ($a) => $a['plan']->id)->all());
        $this->assertSame('stale', $attention->first()['problem']);

        $this->journal($daily, BackupRun::DONE, now()->subHour());
        $this->assertCount(0, app(BackupHealth::class)->attention(), 'a good backup clears it — nothing stored as "seen"');

        $daily->update(['enabled' => false]);
        $this->journal($daily, BackupRun::FAILED, now());
        $this->assertCount(0, app(BackupHealth::class)->attention(), 'a plan switched off is not watched');
    }

    public function test_a_backup_is_checked_by_restoring_it(): void
    {
        $this->needsTools();
        Mail::fake();

        $plan = $this->plan();
        $run = app(Backups::class)->runNow($plan);
        $this->assertSame(BackupRun::DONE, $run->status, (string) $run->error);

        $checked = app(Backups::class)->verify($run);
        $this->assertSame('passed', $checked->verify_status, (string) $checked->verify_error);
        $this->assertNotNull($checked->verified_at);
        $this->assertNoCheckDatabaseLeft();
        Mail::assertNothingQueued();

        // The numbers counted when it was made, made to disagree.
        $file = Storage::disk('local')->path(Backups::DIR.'/'.$run->name);
        $zip = new \ZipArchive;
        $zip->open($file);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertGreaterThan(50, count($manifest['rows']), 'the rows of every table are kept in the manifest');
        $manifest['rows']['users'] += 7;
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();

        $checked = app(Backups::class)->verify($run->fresh());
        $this->assertSame('failed', $checked->verify_status);
        $this->assertStringContainsString('users', (string) $checked->verify_error);
        $this->assertNoCheckDatabaseLeft();
        $this->assertSame(['verify'], app(BackupHealth::class)->attention()->pluck('problem')->all());
        Mail::assertQueued(EventMail::class, fn (EventMail $mail) => $mail->event === CoreLetters::BACKUP_PROBLEM);

        $this->actingAs($this->admin)->get('http://localhost/admin/system/backups')->assertOk()->assertSee('did not restore when checked', false);
    }

    public function test_the_console_restores_from_a_storage_the_journal_does_not_know(): void
    {
        $this->needsTools();

        $plan = $this->plan();
        $run = app(Backups::class)->runNow($plan);
        $name = $run->name;
        $run->copies()->delete();
        $run->delete();

        $storage = app(Backups::class)->systemDestination();
        $this->artisan('torgash:backup-restore', ['--destination' => (string) $storage->id])
            ->expectsOutputToContain($name)->assertSuccessful();

        $this->artisan('torgash:backup-restore', ['--destination' => 'No such storage'])
            ->expectsOutputToContain('There is no storage')->assertFailed();

        $database = 'torgash_health_check';
        $this->superuser(fn ($pdo) => $pdo->exec("drop database if exists $database with (force)"));
        $this->superuser(fn ($pdo) => $pdo->exec("create database $database template template0 encoding 'UTF8' lc_collate 'und' lc_ctype 'und' locale_provider icu icu_locale 'und'"));

        try {
            $this->artisan('torgash:backup-restore', ['name' => $name, '--destination' => $storage->name, '--database' => $database, '--no-files' => true, '--force' => true])
                ->expectsOutputToContain('Restored '.$name)->assertSuccessful();
            $this->assertGreaterThan(50, (int) $this->superuser(
                fn ($pdo) => $pdo->query("select count(*) from pg_tables where schemaname = 'public'")->fetchColumn(), $database,
            ));
        } finally {
            $this->superuser(fn ($pdo) => $pdo->exec("drop database if exists $database with (force)"));
        }
    }

    protected function plan(array $attributes = []): BackupPlan
    {
        static $n = 0;
        $n++;

        $plan = new BackupPlan($attributes + [
            'name' => 'Health '.$n, 'code' => 'health'.$n, 'enabled' => true, 'contents' => ['database'], 'schedule' => [],
            'name_template' => 'h'.$n.'-{date}-{time}',
        ]);
        $plan->format = 'zip';
        $plan->options = ['db_format' => 'custom', 'level' => 'fast'];
        $plan->save();
        $plan->destinations()->attach(app(Backups::class)->systemDestination()->id, ['keep' => json_encode(['last' => 5])]);

        return $plan;
    }

    protected function journal(BackupPlan $plan, string $status, \DateTimeInterface $at): BackupRun
    {
        return BackupRun::create([
            'plan_id' => $plan->id, 'name' => 'j'.uniqid().'.zip', 'status' => $status, 'reason' => 'schedule', 'format' => 'zip',
            'started_at' => $at, 'finished_at' => $at, 'error' => $status === BackupRun::FAILED ? 'pg_dump: connection refused' : null,
        ]);
    }

    /** pg_dump pointed at a database that is not there. */
    protected function breakTheDump(): void
    {
        config(['database.connections.pgsql.database' => 'torgash_no_such_database']);
    }

    protected function needsTools(): void
    {
        if (! Backups::toolVersion('pg_dump') || ! Backups::toolVersion('pg_restore')) {
            $this->markTestSkipped('pg_dump / pg_restore are not available on this machine.');
        }
    }

    protected function assertNoCheckDatabaseLeft(): void
    {
        $left = $this->superuser(fn ($pdo) => $pdo->query("select datname from pg_database where datname like '%\\_check\\_%'")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame([], array_values(array_filter($left, fn ($d) => str_starts_with($d, 'torgash_test'))), 'the check database is dropped');
    }

    protected function superuser(\Closure $do, string $database = 'postgres'): mixed
    {
        $c = config('database.connections.pgsql');
        $pdo = new \PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$database}", $c['username'], (string) $c['password']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $do($pdo);
    }
}
