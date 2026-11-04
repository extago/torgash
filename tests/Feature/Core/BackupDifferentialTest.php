<?php

namespace Tests\Feature\Core;

use App\Core\Backups\Archive\Reader;
use App\Core\Backups\BackupHealth;
use App\Core\Backups\Backups;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A backup of only the files changed since the last full one (PLAN §67.10):
 * it leans on the newest full backup holding the files, by any plan; the
 * first one with nothing to lean on takes every file; restoring puts the
 * full backup's files back first; keeping never deletes a full backup that
 * something leans on; the bell says when it is gone anyway. And "without
 * thumbnails" leaves every `conversions` folder out.
 */
class BackupDifferentialTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Backups::toolVersion('pg_dump') || ! Backups::toolVersion('pg_restore')) {
            $this->markTestSkipped('pg_dump / pg_restore are not available on this machine.');
        }

        Storage::fake('local');

        // The uploaded files from folders of their own, not the shop's.
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'torgash-diff-'.uniqid();
        File::ensureDirectoryExists($this->root.'/public');
        File::ensureDirectoryExists($this->root.'/private');
        config([
            'filesystems.disks.public.root' => $this->root.'/public',
            'filesystems.disks.private.root' => $this->root.'/private',
        ]);
        Storage::forgetDisk('public');
        Storage::forgetDisk('private');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        Storage::forgetDisk('public');
        Storage::forgetDisk('private');

        parent::tearDown();
    }

    public function test_changed_files_lean_on_the_last_full_backup_and_restore_over_it(): void
    {
        $this->picture('7/photo.jpg', '2026-09-30 12:00:00');
        $this->picture('7/conversions/photo-thumb.jpg', '2026-09-30 12:00:00');

        $weekly = $this->plan('weekly', [], 1);
        $daily = $this->plan('daily', ['changed_only' => true, 'without_thumbnails' => true], 7);

        // Nothing to lean on yet: every file goes in — but no thumbnails.
        $first = $this->makeRun($daily, '2026-10-01 03:40:00');
        $this->assertSame(BackupRun::DONE, $first->status, (string) $first->error);
        $this->assertFalse($first->isDifferential(), 'with no full backup it IS the full backup');
        $names = $this->entries($first);
        $this->assertContains('files/public/7/photo.jpg', $names);
        $this->assertNotContains('files/public/7/conversions/photo-thumb.jpg', $names, 'thumbnails left out');
        $this->assertFalse($this->manifest($first)['thumbnails']);
        $this->assertNull($this->manifest($first)['files_since']);

        // The weekly full backup, everything in it.
        $full = $this->makeRun($weekly, '2026-10-02 03:40:00');
        $this->assertSame(BackupRun::DONE, $full->status, (string) $full->error);
        $this->assertContains('files/public/7/conversions/photo-thumb.jpg', $this->entries($full));

        // A new picture after it; the old one untouched.
        $this->picture('8/new.jpg', '2026-10-03 10:00:00');
        $this->picture('8/conversions/new-thumb.jpg', '2026-10-03 10:00:00');

        $diff = $this->makeRun($daily, '2026-10-04 03:40:00');
        $this->assertSame(BackupRun::DONE, $diff->status, (string) $diff->error);
        $this->assertTrue($diff->isDifferential());
        $this->assertSame($full->id, $diff->base_run_id, 'leans on the newest FULL backup, whichever plan made it');
        $this->assertSame($full->started_at->getTimestamp(), $diff->files_since->getTimestamp(), 'since the full backup STARTED');
        $names = $this->entries($diff);
        $this->assertContains('database.dump', $names, 'the database is always whole');
        $this->assertContains('files/public/8/new.jpg', $names);
        $this->assertNotContains('files/public/7/photo.jpg', $names, 'unchanged since the full backup');
        $this->assertNotContains('files/public/8/conversions/new-thumb.jpg', $names);
        $manifest = $this->manifest($diff);
        $this->assertSame($full->name, $manifest['base']);
        $this->assertNotNull($manifest['files_since']);

        // Restoring the files from it: the full backup's first, its own over them.
        File::cleanDirectory($this->root.'/public');
        $said = [];
        $restored = app(Backups::class)->restoreFiles($diff->name, null, null, null, function (string $m) use (&$said) {
            $said[] = $m;
        });
        $this->assertTrue($restored['base_restored']);
        $this->assertStringContainsString($full->name, implode("\n", $said));
        $this->assertFileExists($this->root.'/public/7/photo.jpg', 'from the full backup');
        $this->assertFileExists($this->root.'/public/7/conversions/photo-thumb.jpg', 'the full backup had the thumbnails');
        $this->assertFileExists($this->root.'/public/8/new.jpg', 'from the differential');

        // Keeping: the weekly keeps only the newest, yet the full backup the
        // daily leans on stays until nothing leans on it.
        $full2 = $this->makeRun($weekly, '2026-10-09 03:40:00');
        $this->assertSame(BackupRun::DONE, $full2->status, (string) $full2->error);
        $this->assertTrue($full->fresh()->storedCopies()->exists(), 'kept: a differential leans on it');
        $this->assertSame([], app(Backups::class)->deleteRun($diff->fresh()));
        app(Backups::class)->prune($weekly, app(Backups::class)->systemDestination());
        $this->assertNull(BackupRun::find($full->id), 'nothing leans on it any more: the weekly keeps one');

        // The bell: a differential whose full backup is gone.
        $diff2 = $this->makeRun($daily, '2026-10-10 03:40:00');
        $this->assertSame($full2->id, $diff2->base_run_id);
        $this->assertSame([], app(Backups::class)->deleteRun($full2->fresh()));
        $this->assertNull($diff2->fresh()->base_run_id, 'the journal forgets a deleted base');
        $this->assertFalse($diff2->fresh()->baseKept());
        $orphan = app(BackupHealth::class)->attention()->firstWhere('problem', 'orphan');
        $this->assertNotNull($orphan, 'the bell says the full backup is gone');
        $this->assertSame($daily->id, $orphan['plan']->id);

        // And restoring it is refused in words, never "the pictures of the last day alone".
        try {
            app(Backups::class)->restoreFiles($diff2->name);
            $this->fail('a differential without its base must not restore');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($full2->name, $e->getMessage());
            $this->assertStringContainsString('--base=', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------

    protected function picture(string $path, string $at): void
    {
        $file = $this->root.'/public/'.$path;
        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, 'jpeg '.$path);
        touch($file, strtotime($at));
    }

    protected function plan(string $code, array $options, int $keep): BackupPlan
    {
        $plan = new BackupPlan([
            'name' => ucfirst($code), 'code' => 'diff-'.$code, 'contents' => ['database', 'files'], 'schedule' => [],
            'name_template' => $code.'-{date}-{time}',
        ]);
        $plan->format = 'zip';
        $plan->options = $options + ['db_format' => 'custom', 'level' => 'fast'];
        $plan->save();
        $plan->destinations()->attach(app(Backups::class)->systemDestination()->id, ['keep' => json_encode(['last' => $keep])]);

        return $plan;
    }

    protected function makeRun(BackupPlan $plan, string $at): BackupRun
    {
        Carbon::setTestNow($at);

        try {
            return app(Backups::class)->runNow($plan);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @return list<string> */
    protected function entries(BackupRun $run): array
    {
        $names = [];
        $reader = Reader::file(Storage::disk('local')->path(Backups::DIR.'/'.$run->name));
        $reader->each(function (string $name) use (&$names) {
            $names[] = $name;
        });
        $reader->close();

        return $names;
    }

    /** @return array<string, mixed> */
    protected function manifest(BackupRun $run): array
    {
        $manifest = [];
        $reader = Reader::file(Storage::disk('local')->path(Backups::DIR.'/'.$run->name));
        $reader->each(function (string $name, $stream) use (&$manifest) {
            if ($name === 'manifest.json') {
                $manifest = json_decode((string) stream_get_contents($stream), true);
            }

            return false;
        });
        $reader->close();

        return $manifest;
    }
}
