<?php

namespace App\Core\Backups;

use App\Core\Files\FileStorage;
use App\Models\BackupCopy;
use App\Models\BackupDestination;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\BackgroundTask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Backups of the shop (PLAN §45, §67).
 *
 * A PLAN says what goes in (the database, the uploaded files while they are a
 * local folder, the modules' and themes' code), when, under what name, and the
 * DESTINATIONS it is kept in, each with its own "how many to keep". A RUN is
 * one backup made by it — the journal the list, the clean-up and the restore
 * read. A backup is one zip: `database.dump` (pg_dump's own format), `files/`,
 * `code/`, `manifest.json`.
 *
 * What is NOT in it, on purpose: `.env` (passwords and keys — keep that file
 * apart, the screen says so), `vendor/` (composer install rebuilds it), and the
 * files when they are already in a bucket.
 *
 * Backups are made one at a time: a run is QUEUED and the one background
 * task (`CreateBackup`) takes the queue in order — two pg_dumps at once only
 * slow each other and the shop.
 *
 * The way BACK is a console command (`torgash:backup-restore`), never only a
 * button: an update that broke the admin closes the door a button would be
 * behind — the same thinking as `guard:unblock`.
 */
class Backups
{
    /** Where §45 kept every backup, and updates still keep theirs. */
    public const DIR = 'backups';

    /** Folders of the two disks that are the engine's scratch, not the shop's files. */
    public const SKIP = ['backups', 'exim', '.torgash-probe', 'files-tmp', 'media-tmp'];

    /**
     * The folder medialibrary keeps a picture's conversions in — its
     * thumbnails. Left out of a backup made "without thumbnails" (PLAN
     * §67.10): they are made again from the originals (torgash.media,
     * "Rebuild all thumbnails") and often weigh more than them.
     */
    public const CONVERSIONS = 'conversions';

    /** The names §45 gave, still given to an update's backup (its rollback restores it by name). */
    public const NAME_PATTERN = '/^torgash-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/';

    /** A name the restore accepts: one file, no folder. */
    public const SAFE_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /** How many backups made before updates are kept. */
    public const KEEP_UPDATES = 3;

    /** What the manifest's `key_check` decrypts to under the same APP_KEY. */
    public const KEY_CHECK = 'torgash-backup-key';

    /**
     * The pause before a storage that refused the file is tried once more
     * (seconds). A test that wants a refusal to be quick sets it to 0.
     */
    public int $retryPause = 20;

    public function __construct(protected Destinations $destinations) {}

    // ------------------------------------------------------------ plans and runs

    /**
     * Puts a run of the plan in the queue and makes sure the background task
     * takes it. Null when the plan already has one waiting or being made.
     */
    public function queue(BackupPlan $plan, string $reason, ?int $userId = null): ?BackupRun
    {
        $this->reapStale();

        if ($plan->runs()->whereIn('status', [BackupRun::QUEUED, BackupRun::RUNNING])->exists()) {
            return null;
        }

        $run = $plan->runs()->create([
            'reason' => $reason,
            'status' => BackupRun::QUEUED,
            'contents' => array_values($plan->contents ?? ['database']),
            'user_id' => $userId,
        ]);

        CreateBackup::startIfIdle();

        return $run->refresh();
    }

    /** For the scheduler, which holds only an id. */
    public function queueById(int $planId, string $reason): ?BackupRun
    {
        $plan = BackupPlan::query()->where('enabled', true)->find($planId);

        return $plan ? $this->queue($plan, $reason) : null;
    }

    /**
     * A run made right here, for the console: no queue, no worker.
     *
     * @param  list<string>|null  $contents  instead of the plan's
     */
    public function runNow(BackupPlan $plan, string $reason = 'console', ?array $contents = null, ?callable $say = null): BackupRun
    {
        $run = $plan->runs()->create([
            'reason' => $reason,
            'status' => BackupRun::QUEUED,
            'contents' => array_values($contents ?? $plan->contents ?? ['database']),
        ]);

        return $this->perform($run, $say);
    }

    /**
     * Makes the backup a run stands for and stores it in every destination of
     * its plan. Never throws: what went wrong is the run's `error`, and its
     * status says how far it got (`partial` = made, but not everywhere).
     *
     * @param  callable(string, array<string, mixed>): void|null  $say
     */
    public function perform(BackupRun $run, ?callable $say = null): BackupRun
    {
        $say ??= fn () => null;
        $at = now();
        $run->update(['status' => BackupRun::RUNNING, 'started_at' => $at, 'error' => null]);
        $work = $this->workDir();

        try {
            $plan = $run->plan ?? throw new RuntimeException('The backup plan has been deleted.');
            $destinations = $plan->destinations()->get();

            if ($destinations->isEmpty()) {
                throw new RuntimeException('The plan has no storage to keep the backup in.');
            }

            $format = in_array($plan->format, BackupPlan::FORMATS, true) ? $plan->format : 'zip';
            $encrypt = $plan->encrypts();
            $password = $encrypt ? $plan->password() : '';
            $name = Names::file($plan->name_template ?: '{shop}-{plan}-{date}-{time}', $plan, $at, $plan->extension());

            // Only the files changed since the last full backup (PLAN
            // §67.10): the base is the newest full backup holding the files
            // that is still kept — by any plan. Without one every file goes
            // in, and the manifest says so.
            $contents = (array) ($run->contents ?: $plan->contents);
            $base = in_array('files', $contents, true) && (bool) $plan->option('changed_only') ? $this->lastFull() : null;

            $staged = $this->stage($work, $contents, $say,
                ['plan' => $plan->code, 'format' => $format, 'encrypted' => $encrypt],
                [
                    'db_format' => $plan->option('db_format', 'custom'),
                    'env' => $encrypt && (bool) $plan->option('env'),
                    'files_since' => $base?->started_at,
                    'base' => $base?->name,
                    'thumbnails' => ! (bool) $plan->option('without_thumbnails'),
                ],
            );
            $manifest = $staged['manifest'];

            if ($format === 'folder') {
                $bytes = array_sum(array_map(fn (array $entry) => (int) filesize($entry[1]), $staged['entries']));
                $sha256 = null;
                $send = fn (Store $store, string $path) => $this->uploadFolder($store, $path, $staged['entries']);
            } else {
                $say('Packing', []);
                $file = $work.DIRECTORY_SEPARATOR.$name;
                $this->build($file, $format, $staged['entries'], $plan->level(), $password);
                $bytes = filesize($file) ?: null;
                $sha256 = hash_file('sha256', $file) ?: null;
                $send = fn (Store $store, string $path) => $this->upload($store, $path, $file);
            }

            $run->update([
                'name' => $name,
                'bytes' => $bytes,
                'sha256' => $sha256,
                'contents' => $manifest['contents'],
                'format' => $format,
                'encrypted' => $encrypt,
                'base_run_id' => $manifest['files_since'] !== null ? $base?->id : null,
                'files_since' => $manifest['files_since'] !== null ? $base?->started_at : null,
            ]);

            $stored = 0;
            $failures = [];

            foreach ($destinations as $destination) {
                $say('Storing the backup in :storage', ['storage' => $destination->name]);
                unset($store);
                $folder = Names::folder((string) $destination->path_template, $plan, $at);
                $path = ($folder === '' ? '' : $folder.'/').$name;

                // A storage that refuses is tried once more after a pause (PLAN
                // §67.11): a server that was busy for a moment usually is not
                // any longer, and the whole upload costs less than a backup
                // nobody has. S3 retries each part on its own before this.
                for ($attempt = 1; ; $attempt++) {
                    try {
                        $store = $this->destinations->open($destination);
                        $send($store, $path);
                        $run->copies()->create(['destination_id' => $destination->id, 'path' => $path, 'status' => BackupCopy::STORED]);
                        $stored++;
                        break;
                    } catch (Throwable $e) {
                        // Half a folder is not a backup: taken away again, if it can be.
                        if ($format === 'folder' && isset($store)) {
                            try {
                                $store->deleteFolder($path);
                            } catch (Throwable) {
                            }
                        }
                        $message = Str::limit(Destinations::reason($e), 800);

                        if ($attempt < 2) {
                            $say(':storage did not take the file (:error) — trying once more', ['storage' => $destination->name, 'error' => Str::limit($message, 200)]);
                            sleep($this->retryPause);

                            continue;
                        }

                        $run->copies()->create(['destination_id' => $destination->id, 'path' => $path, 'status' => BackupCopy::FAILED, 'error' => $message]);
                        $failures[] = $destination->name.': '.$message;
                        break;
                    }
                }
            }

            $run->update([
                'status' => $failures === [] ? BackupRun::DONE : ($stored > 0 ? BackupRun::PARTIAL : BackupRun::FAILED),
                'error' => $failures ? implode("\n", $failures) : null,
                'finished_at' => now(),
            ]);

            // Only after a backup that worked: a failed run never costs the
            // good copies before it.
            if ($stored > 0) {
                foreach ($destinations as $destination) {
                    $this->prune($plan, $destination);
                }
            }
        } catch (Throwable $e) {
            $run->update([
                'status' => BackupRun::FAILED,
                'error' => Str::limit(trim($e->getMessage()) ?: get_class($e), 2000),
                'finished_at' => now(),
            ]);
        } finally {
            File::deleteDirectory($work);
        }

        $this->forgetOldFailures();
        $run->refresh();

        if (in_array($run->status, [BackupRun::FAILED, BackupRun::PARTIAL], true)) {
            $this->tell($run, $run->status, $run->status === BackupRun::FAILED
                ? __('The backup by ":plan" failed: :error', ['plan' => $run->plan?->name ?? '?', 'error' => (string) $run->error])
                : __('The backup by ":plan" was made but not stored everywhere: :error', ['plan' => $run->plan?->name ?? '?', 'error' => (string) $run->error]));
        }

        return $run;
    }

    /**
     * Restores a backup into a database made for the purpose, compares the
     * rows of every table with the numbers counted when it was made, and
     * drops that database (PLAN §67.6). A backup never restored is a hope,
     * not a backup. Never throws: the run's `verify_*` say how it went.
     *
     * @param  callable(string): void|null  $say
     */
    public function verify(BackupRun $run, ?callable $say = null): BackupRun
    {
        $say ??= fn () => null;

        // Nothing has been restored, so nothing is known about the backup:
        // `blocked`, never `failed` — which says the backup itself is bad.
        if (! $this->mayCreateDatabases()) {
            $reason = __('The shop\'s database user may not create a database, and checking needs one for a moment: give it CREATEDB ("alter role … createdb").');
            $run->update(['verified_at' => now(), 'verify_status' => 'blocked', 'verify_error' => $reason]);
            $this->tell($run, 'verify_blocked', __('The backup :name by ":plan" was made, but could not be checked: :error', [
                'name' => $run->name, 'plan' => $run->plan?->name ?? '?', 'error' => $reason,
            ]));

            return $run->refresh();
        }

        $temp = substr(preg_replace('/[^a-z0-9_]/', '_', strtolower($this->connection()['database'])), 0, 40).'_check_'.$run->id;
        $problems = [];
        $made = false;

        try {
            $pdo = $this->pdo();
            $pdo->exec('drop database if exists "'.$temp.'" with (force)');
            $pdo->exec('create database "'.$temp.'" template template0 encoding \'UTF8\' '.$this->localeClause($pdo));
            $made = true;

            $say('Restoring into a database of its own');
            $manifest = $this->restore((string) $run->name, $temp, false, fn (string $m) => $say($m));
            $expected = (array) ($manifest['rows'] ?? []);

            $say('Comparing the rows');
            $check = $this->pdo($temp);
            $found = [];
            foreach ($check->query(self::COUNT_ROWS)->fetchAll(\PDO::FETCH_KEY_PAIR) as $table => $count) {
                $found[$table] = (int) $count;
            }

            if ($expected === []) {
                // Made before the counts were kept: that it restored is all
                // that can be said — and a shop without its tables is not.
                if (count($found) < 20) {
                    $problems[] = __('Only :n tables came back.', ['n' => count($found)]);
                }
            }

            foreach ($expected as $table => $count) {
                if (! array_key_exists($table, $found)) {
                    $problems[] = __('The table :table did not come back.', ['table' => $table]);
                } elseif ($found[$table] !== (int) $count) {
                    $problems[] = __(':table: :found rows instead of :count.', ['table' => $table, 'found' => $found[$table], 'count' => $count]);
                }
            }
            $check = null;
        } catch (Throwable $e) {
            $problems[] = Destinations::reason($e);
        } finally {
            if ($made) {
                try {
                    $this->pdo()->exec('drop database if exists "'.$temp.'" with (force)');
                } catch (Throwable) {
                }
            }
        }

        $run->update([
            'verified_at' => now(),
            'verify_status' => $problems === [] ? 'passed' : 'failed',
            'verify_error' => $problems === [] ? null : Str::limit(implode("\n", array_slice($problems, 0, 20)), 2000),
        ]);

        if ($problems !== []) {
            $this->tell($run, 'verify', __('The backup :name by ":plan" did not restore when checked: :error', [
                'name' => $run->name, 'plan' => $run->plan?->name ?? '?', 'error' => Str::limit(implode(' ', $problems), 500),
            ]));
        }

        return $run->refresh();
    }

    /**
     * Whether the shop's database user may make the database a check restores
     * into. Not knowing counts as yes: the check itself then says what is wrong.
     */
    public function mayCreateDatabases(): bool
    {
        try {
            return (bool) $this->pdo()->query('select rolcreatedb or rolsuper from pg_roles where rolname = current_user')->fetchColumn();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * A copy that was not stored is sent once more (PLAN §67.11) from a copy
     * that was — the server's own first — without making the backup again:
     * fetched into the work folder, then uploaded the way the run did it. The
     * run's status follows its copies: `done` once every one is there.
     */
    public function resend(BackupCopy $copy, ?callable $say = null): BackupCopy
    {
        $say ??= fn () => null;
        $run = $copy->run ?? throw new RuntimeException('The backup this copy belongs to is gone.');
        $destination = $copy->destination ?? throw new RuntimeException('The storage this copy was meant for is gone.');

        if (! $run->finished()) {
            throw new RuntimeException('The backup is still being made.');
        }

        [$from, $fromPath] = $this->locate((string) $run->name)
            ?? throw new RuntimeException("No stored copy of {$run->name} is left to send it from.");
        $work = $this->workDir();
        $path = (string) $copy->path;

        try {
            $say('Fetching the backup', []);
            $entries = [];

            if ($run->isFolder()) {
                $prefix = trim($fromPath, '/').'/';

                foreach ($from->files($fromPath, true) as $file) {
                    $name = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : basename($file);
                    $local = $work.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name);
                    File::ensureDirectoryExists(dirname($local));
                    $this->fetch($from, $file, $local);
                    $entries[] = [$name, $local];
                }

                if (! in_array('manifest.json', array_column($entries, 0), true)) {
                    throw new RuntimeException("The copy of {$run->name} holds no manifest.json: not a backup that finished.");
                }
            } else {
                $local = $work.DIRECTORY_SEPARATOR.basename($fromPath);
                $this->fetch($from, $fromPath, $local);
            }

            $say('Storing the backup in :storage', ['storage' => $destination->name]);
            $store = $this->destinations->open($destination);

            try {
                $run->isFolder() ? $this->uploadFolder($store, $path, $entries) : $this->upload($store, $path, $local);
            } catch (Throwable $e) {
                if ($run->isFolder()) {
                    try {
                        $store->deleteFolder($path);
                    } catch (Throwable) {
                    }
                }
                $copy->update(['error' => Str::limit(Destinations::reason($e), 800)]);
                $this->settle($run);

                throw $e;
            }

            $copy->update(['status' => BackupCopy::STORED, 'error' => null]);
            $this->settle($run);

            // The keeping rules for this storage never ran for the run that failed.
            if ($run->plan) {
                $this->prune($run->plan, $destination);
            }
        } finally {
            File::deleteDirectory($work);
        }

        return $copy->refresh();
    }

    /** A finished run's status from its copies: `done` with every one stored, `partial` while some are not. */
    protected function settle(BackupRun $run): void
    {
        $failed = $run->copies()->where('status', BackupCopy::FAILED)->with('destination')->get();

        $run->update([
            'status' => $failed->isEmpty() ? BackupRun::DONE : BackupRun::PARTIAL,
            'error' => $failed->isEmpty() ? null
                : $failed->map(fn (BackupCopy $c) => ($c->destination?->name ?? '?').': '.$c->error)->implode("\n"),
        ]);
    }

    /** A storage's file into a local one, streamed. */
    protected function fetch(Store $from, string $path, string $local): void
    {
        $read = $from->read($path);

        try {
            $this->copyStream($read, $local);
        } finally {
            if (is_resource($read)) {
                fclose($read);
            }
        }
    }

    /** The newest backup of a plan that can be checked: made, and kept somewhere. */
    public function latestToVerify(BackupPlan $plan): ?BackupRun
    {
        return BackupRun::query()->where('plan_id', $plan->id)
            ->whereIn('status', [BackupRun::DONE, BackupRun::PARTIAL])
            ->whereHas('storedCopies')
            ->orderByDesc('id')->first();
    }

    /** A letter about a backup gone wrong (CoreLetters::BACKUP_PROBLEM). */
    public function tell(?BackupRun $run, string $kind, string $problem, ?BackupPlan $plan = null): void
    {
        try {
            \App\Core\Mail\Letters::toShop(\App\Core\Mail\CoreLetters::BACKUP_PROBLEM, [
                'plan' => ($plan ?? $run?->plan)?->name ?? '',
                'kind' => $kind,
                'problem' => $problem,
            ]);
        } catch (Throwable) {
            // A letter that cannot leave must not turn a made backup into an error.
        }
    }

    /**
     * The backup an update makes before it touches anything (§45.3): the
     * database only, on the server's own folder, under the name its rollback
     * will restore. Made right here and thrown on failure — the update must
     * not start without it.
     */
    public function forUpdate(): string
    {
        $destination = $this->systemDestination();
        $at = now();
        $name = 'torgash-'.$at->format('Y-m-d-His').'.zip';
        $work = $this->workDir();

        $run = BackupRun::create([
            'reason' => 'update', 'status' => BackupRun::RUNNING, 'name' => $name,
            'contents' => ['database'], 'started_at' => $at,
        ]);

        try {
            $zipPath = $work.DIRECTORY_SEPARATOR.$name;
            $manifest = $this->pack($zipPath, ['database'], fn () => null, ['reason' => 'update']);
            $this->upload($this->destinations->open($destination), $name, $zipPath);

            $run->update([
                'status' => BackupRun::DONE, 'bytes' => filesize($zipPath) ?: null,
                'sha256' => hash_file('sha256', $zipPath) ?: null, 'contents' => $manifest['contents'], 'finished_at' => now(),
            ]);
            $run->copies()->create(['destination_id' => $destination->id, 'path' => $name, 'status' => BackupCopy::STORED]);
        } catch (Throwable $e) {
            $run->update(['status' => BackupRun::FAILED, 'error' => Str::limit($e->getMessage(), 2000), 'finished_at' => now()]);

            throw $e;
        } finally {
            File::deleteDirectory($work);
        }

        $this->pruneWithoutPlan();

        return $name;
    }

    /**
     * A run still "running" whose task is no longer is written off — and one
     * made from the console (no task) after twelve hours: that process is
     * long gone.
     */
    public function reapStale(): void
    {
        BackupRun::query()->where('status', BackupRun::RUNNING)->whereNull('task_id')
            ->where('started_at', '<', now()->subHours(12))
            ->update([
                'status' => BackupRun::FAILED,
                'error' => 'Stopped before it was finished: the process that made it ended.',
                'finished_at' => now(),
            ]);

        BackupRun::query()->where('status', BackupRun::RUNNING)->whereNotNull('task_id')->get()
            ->each(function (BackupRun $run) {
                $status = BackgroundTask::query()->whereKey($run->task_id)->value('status');

                if (! in_array($status, [BackgroundTask::QUEUED, BackgroundTask::RUNNING], true)) {
                    $run->update([
                        'status' => BackupRun::FAILED,
                        'error' => 'Stopped before it was finished: the background task ended.',
                        'finished_at' => now(),
                    ]);
                }
            });
    }

    /**
     * The newest FULL backup holding the files that is still kept somewhere
     * — by any plan: what a backup of "only the files changed since" leans
     * on (PLAN §67.10). Null when there is none; every file goes in then.
     */
    public function lastFull(): ?BackupRun
    {
        return BackupRun::query()
            ->whereIn('status', [BackupRun::DONE, BackupRun::PARTIAL])
            ->whereNull('files_since')
            ->whereJsonContains('contents', 'files')
            ->whereNotNull('started_at')
            ->whereHas('storedCopies')
            ->orderByDesc('started_at')->orderByDesc('id')
            ->first();
    }

    // ------------------------------------------------------------ keeping and deleting

    /** Applies the plan's "how many to keep" in one destination; returns how many went. */
    public function prune(BackupPlan $plan, BackupDestination $destination): int
    {
        $pivot = DB::table('backup_plan_destination')->where(['plan_id' => $plan->id, 'destination_id' => $destination->id])->value('keep');
        $rules = (array) json_decode((string) $pivot, true);

        $copies = BackupCopy::query()
            ->with('run')
            ->where('destination_id', $destination->id)
            ->where('status', BackupCopy::STORED)
            ->whereHas('run', fn ($q) => $q->where('plan_id', $plan->id)->whereIn('status', [BackupRun::DONE, BackupRun::PARTIAL]))
            ->get();

        return $this->expire($copies, $rules, $destination);
    }

    /** Updates' backups (and §45's found beside them): the newest few. */
    public function pruneWithoutPlan(): int
    {
        $destination = $this->systemDestination();

        $copies = BackupCopy::query()
            ->with('run')
            ->where('destination_id', $destination->id)
            ->where('status', BackupCopy::STORED)
            ->whereHas('run', fn ($q) => $q->whereNull('plan_id')->whereIn('reason', ['update', 'found'])->where('status', BackupRun::DONE))
            ->get();

        return $this->expire($copies, ['last' => self::KEEP_UPDATES], $destination);
    }

    /** Deletes a backup everywhere it is kept; returns what could not be deleted. */
    public function deleteRun(BackupRun $run): array
    {
        $problems = [];

        foreach ($run->copies()->with('destination')->where('status', BackupCopy::STORED)->get() as $copy) {
            try {
                if ($copy->destination) {
                    $this->remove($this->destinations->open($copy->destination), $run, (string) $copy->path);
                }
            } catch (Throwable $e) {
                $problems[] = ($copy->destination?->name ?? '?').': '.Str::limit(Destinations::reason($e), 300);
            }
        }

        if ($problems === []) {
            $run->delete();
        }

        return $problems;
    }

    /** @param  Collection<int, BackupCopy>  $copies */
    protected function expire(Collection $copies, array $rules, BackupDestination $destination): int
    {
        // A full backup that a backup of changed files leans on is kept as
        // long as that one is (PLAN §67.10) — pinned by it, in effect.
        $bases = BackupRun::query()->whereIn('base_run_id', $copies->pluck('run_id')->unique()->all())
            ->whereHas('storedCopies')->pluck('base_run_id')->flip();

        $expired = Retention::expired($copies->map(fn (BackupCopy $copy) => [
            'id' => $copy->id,
            'at' => $copy->run->started_at ?? $copy->run->created_at,
            'bytes' => $copy->run->bytes,
            'pinned' => (bool) $copy->run->pinned || $bases->has($copy->run_id),
        ])->values()->all(), $rules);

        if ($expired === []) {
            return 0;
        }

        $store = $this->destinations->open($destination);
        $gone = 0;

        foreach ($copies->whereIn('id', $expired) as $copy) {
            try {
                $this->remove($store, $copy->run, (string) $copy->path);
            } catch (Throwable) {
                // Still there: it stays in the journal and is tried next time.
                continue;
            }

            $run = $copy->run;
            $copy->delete();
            $gone++;

            if (! $run->copies()->exists()) {
                $run->delete();
            }
        }

        return $gone;
    }

    /** A copy taken away: a file, or the folder of one made without an archive. */
    protected function remove(Store $store, BackupRun $run, string $path): void
    {
        $run->isFolder() ? $store->deleteFolder($path) : $store->delete($path);
    }

    /** A failed run says what went wrong; after a month it has said it. */
    protected function forgetOldFailures(): void
    {
        BackupRun::query()->where('status', BackupRun::FAILED)->where('created_at', '<', now()->subDays(30))->delete();
    }

    // ------------------------------------------------------------ finding one

    /** The server's own folder — always there; an update keeps its backup in it. */
    public function systemDestination(): BackupDestination
    {
        return BackupDestination::query()->where('system', true)->orderBy('id')->first()
            ?? BackupDestination::create([
                'name' => (string) __('This server', [], content_base_locale()), 'driver' => 'disk', 'system' => true,
                'settings' => ['disk' => 'local', 'root' => self::DIR],
            ]);
    }

    public function store(BackupDestination $destination): Store
    {
        return $this->destinations->open($destination);
    }

    /**
     * Where a backup of this name can be read: [store, path], or null. The
     * journal first; then — when the database cannot be asked, which is when a
     * restore is needed most — the folders §45 and the updates use.
     *
     * @return array{0: Store, 1: string, 2: BackupRun|null}|null
     */
    public function locate(string $name): ?array
    {
        if (preg_match(self::SAFE_NAME, $name) !== 1 || str_contains($name, '..')) {
            return null;
        }

        try {
            $copies = BackupCopy::query()->with(['destination', 'run.plan'])->where('status', BackupCopy::STORED)
                ->whereHas('run', fn ($q) => $q->where('name', $name))
                ->orderByDesc('id')->get();

            // The server's own copy first: it is the quickest to fetch.
            foreach ($copies->sortBy(fn (BackupCopy $copy) => $copy->destination?->driver === 'disk' ? 0 : 1) as $copy) {
                if (! $copy->destination) {
                    continue;
                }

                try {
                    $store = $this->destinations->open($copy->destination);
                    $run = $copy->run;
                    $there = $run?->isFolder()
                        ? $store->exists($copy->path.'/manifest.json')
                        : $store->exists((string) $copy->path);

                    if ($there) {
                        return [$store, (string) $copy->path, $run];
                    }
                } catch (Throwable) {
                    // That storage cannot be reached: try the next copy.
                }
            }
        } catch (Throwable) {
            // No journal to ask.
        }

        foreach (FileStorage::DISKS as $disk) {
            try {
                $store = new Store(Storage::disk($disk), self::DIR);

                if ($store->exists($name) || $store->exists($name.'/manifest.json')) {
                    return [$store, $name, null];
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    /**
     * The backups a storage holds, read from the storage ITSELF, not the
     * journal (PLAN §67.7): a database restored from an older backup has an
     * older journal, and a new server none. A file the engine names, or a
     * folder with a manifest.json — under any folder of the template.
     *
     * @return Collection<int, array{name: string, path: string, bytes: ?int, folder: bool}>
     */
    public function listIn(BackupDestination $destination): Collection
    {
        $store = $this->destinations->open($destination);
        $found = collect();

        foreach ($store->files('', true) as $path) {
            $base = basename($path);

            if ($base === 'manifest.json' && str_contains($path, '/')) {
                $folder = dirname($path);
                $name = basename($folder);
                if (preg_match(self::SAFE_NAME, $name) && ! str_contains(dirname($folder), 'files/') ) {
                    $found->push(['name' => $name, 'path' => $folder, 'bytes' => null, 'folder' => true]);
                }

                continue;
            }

            if (preg_match(self::SAFE_NAME, $base) && preg_match('/\.(zip|tar|tar\.gz|enc)$/', $base) && ! preg_match('#(^|/)[^/]+/(files|code)/#', $path)) {
                $found->push(['name' => $base, 'path' => $path, 'bytes' => $store->size($path), 'folder' => false]);
            }
        }

        // A folder backup's own files are not backups of their own.
        $folders = $found->where('folder', true)->pluck('path')->all();

        return $found->reject(fn (array $b) => ! $b['folder'] && collect($folders)->contains(fn (string $f) => str_starts_with($b['path'], $f.'/')))
            ->sortByDesc('name')->values();
    }

    /** @return array{0: Store, 1: string, 2: BackupRun|null}|null */
    public function locateIn(BackupDestination $destination, string $name): ?array
    {
        $hit = $this->listIn($destination)->firstWhere('name', $name);

        return $hit ? [$this->destinations->open($destination), $hit['path'], null] : null;
    }

    /**
     * The backups that can be restored, newest first — from the journal, or,
     * without one, from the server's folders.
     *
     * @return Collection<int, array{name: string, bytes: ?int, at: Carbon, plan: string, where: string}>
     */
    public function available(): Collection
    {
        try {
            return BackupRun::query()->with(['plan', 'storedCopies.destination'])
                ->whereIn('status', [BackupRun::DONE, BackupRun::PARTIAL])
                ->whereHas('storedCopies')
                ->orderByDesc('started_at')->orderByDesc('id')->get()
                ->map(fn (BackupRun $run) => [
                    'name' => (string) $run->name,
                    'bytes' => $run->bytes,
                    'at' => $run->started_at ?? $run->created_at,
                    'plan' => $run->plan?->name ?? $run->reason,
                    'where' => $run->storedCopies->map(fn (BackupCopy $c) => $c->destination?->name)->filter()->implode(', '),
                ]);
        } catch (Throwable) {
        }

        $found = collect();

        foreach (FileStorage::DISKS as $disk) {
            try {
                foreach (Storage::disk($disk)->files(self::DIR) as $path) {
                    $name = basename($path);

                    if (preg_match(self::SAFE_NAME, $name)) {
                        $found->push([
                            'name' => $name,
                            'bytes' => (int) Storage::disk($disk)->size($path),
                            'at' => Carbon::createFromTimestamp(Storage::disk($disk)->lastModified($path)),
                            'plan' => '',
                            'where' => $disk,
                        ]);
                    }
                }
            } catch (Throwable) {
            }
        }

        return $found->sortByDesc('name')->values();
    }

    // ------------------------------------------------------------ making the file

    /**
     * What a backup holds, laid out before anything is packed: the dump and
     * the manifest in the work folder, the files and code where they are. The
     * order is the order every form writes and every reader hands back:
     * manifest, `.env`, database, then files and code — so a restore reads
     * what it needs first without unpacking the pictures.
     *
     * @param  list<string>  $contents  database / files / code
     * @param  array{db_format?: string, env?: bool, files_since?: ?Carbon, base?: ?string, thumbnails?: bool}  $options
     * @return array{manifest: array<string, mixed>, entries: list<array{0: string, 1: string}>}
     */
    protected function stage(string $work, array $contents, callable $say, array $extra = [], array $options = []): array
    {
        $files = app(FileStorage::class);
        $withFiles = in_array('files', $contents, true);
        $plain = ($options['db_format'] ?? 'custom') === 'plain';
        // Only the files changed since a moment — the start of the full
        // backup this one leans on — and whether thumbnails go in (§67.10).
        $since = ($options['files_since'] ?? null)?->getTimestamp();
        $thumbnails = (bool) ($options['thumbnails'] ?? true);

        $say('Dumping the database', []);
        $dumpName = $plain ? 'database.sql' : 'database.dump';
        $dump = $work.DIRECTORY_SEPARATOR.$dumpName;

        // The rows of every table counted in the very snapshot pg_dump takes:
        // a check by restoring then compares numbers that are exact, not
        // "about the same" on a shop taking orders while it is dumped.
        [$snapshot, $rows, $holder] = $this->snapshot();

        try {
            $this->pg('pg_dump', [
                ...($plain ? ['-Fp', '--clean', '--if-exists'] : ['-Fc']),
                ...($snapshot ? ['--snapshot='.$snapshot] : []),
                '--no-owner', '--no-acl', '-f', $dump, $this->connection()['database'],
            ]);
        } finally {
            $holder = null;
        }

        $head = [];
        $tail = [];
        $packed = ['database'];
        $counts = [];

        // .env goes only inside an encrypted backup (the plan's form says so).
        $env = ($options['env'] ?? false) && is_file(base_path('.env'));
        if ($env) {
            $head[] = ['env/.env', base_path('.env')];
        }
        $head[] = [$dumpName, $dump];

        if ($withFiles && ! $files->onS3()) {
            foreach (FileStorage::DISKS as $diskName) {
                $say('Adding the :disk files', ['disk' => $diskName]);
                $tree = $this->collectTree((string) config("filesystems.disks.$diskName.root"), 'files/'.$diskName, self::SKIP, $since, $thumbnails);
                $counts[$diskName] = count($tree);
                array_push($tail, ...$tree);
            }
            $packed[] = 'files';
        }

        if (in_array('code', $contents, true)) {
            $say('Adding the modules and themes', []);
            foreach (['modules' => 'torgash.modules_path', 'themes' => 'torgash.themes_path'] as $part => $key) {
                $tree = $this->collectTree((string) config($key), 'code/'.$part);
                $counts[$part] = count($tree);
                array_push($tail, ...$tree);
            }
            $packed[] = 'code';
        }

        $manifest = [
            'engine' => config('torgash.version'),
            'created_at' => now()->toIso8601String(),
            'app_url' => config('app.url'),
            'database' => $this->connection()['database'],
            'migrations' => DB::table('migrations')->count(),
            'format' => 'zip',
            'encrypted' => false,
            'database_format' => $plain ? 'plain' : 'custom',
            'contents' => $packed,
            'env' => $env,
            'files_in_bucket' => $withFiles && $files->onS3(),
            // Changed files only: since when, and the full backup to restore
            // first. Null — every file is in (or none: a bucket, no files).
            'files_since' => in_array('files', $packed, true) && $since !== null ? Carbon::createFromTimestamp($since)->toIso8601String() : null,
            'base' => in_array('files', $packed, true) && $since !== null ? ($options['base'] ?? null) : null,
            'thumbnails' => $thumbnails,
            'counts' => $counts,
            'rows' => $rows,
            // Says on a new server whether its APP_KEY is this one (keyMatches()).
            'key_check' => Crypt::encryptString(self::KEY_CHECK),
        ];
        $manifest = $extra + $manifest;

        $path = $work.DIRECTORY_SEPARATOR.'manifest.json';
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ['manifest' => $manifest, 'entries' => [['manifest.json', $path], ...$head, ...$tail]];
    }

    /**
     * Writes the staged entries as one file: zip (AES-256 per entry with a
     * password), tar, or tar.gz — and a tar with a password encrypted whole,
     * the way `openssl enc` does (`Archive\Cipher`).
     *
     * @param  list<array{0: string, 1: string}>  $entries
     */
    protected function build(string $target, string $format, array $entries, int $level, string $password = ''): void
    {
        if ($format === 'zip') {
            $zip = new ZipArchive;
            if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create '.$target);
            }

            foreach ($entries as [$name, $source]) {
                $zip->addFile($source, $name);

                // Pictures, videos and the custom dump are compressed
                // already: deflating them again costs time and saves nothing.
                $stored = preg_match('/\.(jpe?g|png|webp|gif|avif|mp4|webm|mov|zip|gz|glb|pdf|dump)$/i', $name) === 1;
                $zip->setCompressionName($name, $stored ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE, $stored ? 0 : $level);

                if ($password !== '') {
                    $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password);
                }
            }

            if (! $zip->close()) {
                throw new RuntimeException('Could not write '.$target.': '.$zip->getStatusString());
            }

            return;
        }

        $tar = $password === '' ? $target : $target.'.plain';
        $writer = new Archive\TarWriter($tar, $format === 'tar.gz' ? $level : null);
        foreach ($entries as [$name, $source]) {
            $writer->addFile($name, $source);
        }
        $writer->close();

        if ($password !== '') {
            Archive\Cipher::encryptFile($tar, $target, $password);
            @unlink($tar);
        }
    }

    /** A zip of $contents at $zipPath (an update's backup); returns its manifest. */
    protected function pack(string $zipPath, array $contents, callable $say, array $extra = []): array
    {
        $staged = $this->stage(dirname($zipPath), $contents, $say, $extra);
        $say('Packing', []);
        $this->build($zipPath, 'zip', $staged['entries'], 6);

        return $staged['manifest'];
    }

    protected function upload(Store $store, string $path, string $file): void
    {
        $stream = fopen($file, 'rb');

        try {
            $store->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * A backup "without an archive": every entry its own file under $folder,
     * the manifest LAST — a folder without one is a backup that never
     * finished, and nothing reads it as one.
     *
     * @param  list<array{0: string, 1: string}>  $entries
     */
    protected function uploadFolder(Store $store, string $folder, array $entries): void
    {
        $manifest = null;

        foreach ($entries as [$name, $source]) {
            if ($name === 'manifest.json') {
                $manifest = $source;

                continue;
            }

            $this->upload($store, $folder.'/'.$name, $source);
        }

        if ($manifest !== null) {
            $this->upload($store, $folder.'/manifest.json', $manifest);
        }
    }

    // ------------------------------------------------------------ the way back

    /**
     * Restores a backup: the database (into this one, or `$database` — an
     * existing, empty database to check a backup without touching the shop),
     * the files when asked and when it has them, and the modules' and themes'
     * code when asked (`$withCode`). Any form the engine makes (§67.3): zip,
     * tar, tar.gz, encrypted or not, or a folder.
     *
     * `$file` is a backup on this server's disk — a file, or the folder of
     * one made "without an archive" — the way back on a NEW server, whose
     * journal is empty and whose storages are not configured yet.
     *
     * `$password` opens an encrypted backup; without it the journal's plan
     * gives its own, when this server can still read it. `$withEnv` puts the
     * backup's `.env` in place (the current one kept as .env.before-restore).
     *
     * The manifest returned carries `key_matches`: whether this server's
     * APP_KEY (or, with `$withEnv`, the restored one) is the one the backup
     * was made under. Without it gift card codes, the mail password, second
     * factors and storage passwords stay unreadable.
     *
     * @param  callable(string): void|null  $say
     * @return array<string, mixed> the backup's manifest
     */
    public function restore(string $name, ?string $database = null, bool $withFiles = true, ?callable $say = null, bool $withCode = false, ?string $file = null, ?string $password = null, bool $withEnv = false, ?BackupDestination $from = null, bool $lock = true, ?string $baseFile = null): array
    {
        $say ??= fn () => null;
        $work = $this->workDir();
        $reader = null;

        try {
            $reader = $this->open($name, $file, $password, $work, $say, $from);

            // The head: manifest, .env, database. Everything after it is
            // files and code — not read now.
            $manifest = [];
            $dump = null;
            $envFile = null;

            $reader->each(function (string $entry, $stream) use (&$manifest, &$dump, &$envFile, $work) {
                if ($entry === 'manifest.json') {
                    $manifest = json_decode((string) stream_get_contents($stream), true) ?: [];
                } elseif ($entry === 'env/.env') {
                    $envFile = $work.DIRECTORY_SEPARATOR.'backup.env';
                    $this->copyStream($stream, $envFile);
                } elseif ($entry === 'database.dump' || $entry === 'database.sql') {
                    $dump = $work.DIRECTORY_SEPARATOR.$entry;
                    $this->copyStream($stream, $dump);
                } elseif (str_starts_with($entry, 'files/') || str_starts_with($entry, 'code/')) {
                    return false;
                }

                return true;
            });

            if ($dump === null) {
                throw new RuntimeException('The backup holds no database.');
            }

            $say('Restoring the database');
            $target = $database ?: $this->connection()['database'];
            str_ends_with($dump, '.sql') ? $this->restoreSql($dump, $target) : $this->restoreDump($dump, $target);

            $files = $withFiles && $database === null && in_array('files', $manifest['contents'] ?? [], true);
            $code = $withCode && $database === null && in_array('code', $manifest['contents'] ?? [], true);

            // A backup of only the files changed since a full one (PLAN
            // §67.10): that full backup's files go back FIRST, these over
            // them. Without it the shop would come back with the pictures of
            // the last day alone — refused, in words.
            $manifest['base_restored'] = false;
            if ($files && ! empty($manifest['files_since'])) {
                $this->restoreBase($manifest, $baseFile, $password, $work, $say, $from);
                $manifest['base_restored'] = true;
            }

            if ($files || $code) {
                $say($files ? 'Restoring the files' : 'Restoring the modules and themes');
                $this->restoreEntries($reader, $files, $code);
            }

            $manifest['env_in_backup'] = $envFile !== null;
            $manifest['env_written'] = false;

            if ($envFile !== null && $withEnv && $database === null) {
                if (is_file(base_path('.env'))) {
                    copy(base_path('.env'), base_path('.env.before-restore'));
                }
                copy($envFile, base_path('.env'));
                $manifest['env_written'] = true;
            }

            $manifest['key_matches'] = $this->keyMatches($manifest['key_check'] ?? null, $manifest['env_written'] ? $envFile : null);

            if ($database === null) {
                DB::purge();

                // The dump was taken WHILE its own run was being made, so the
                // restored journal holds that run as "running" for ever: the
                // plan would never start again and the list would poll every
                // three seconds (the restore drill, 2026-11-03; an update's
                // rollback the same). Such rows are the snapshot's, not runs.
                try {
                    if (Schema::hasTable('backup_runs')) {
                        BackupRun::query()->whereIn('status', [BackupRun::QUEUED, BackupRun::RUNNING])->delete();
                    }
                } catch (Throwable) {
                }

                // A new server's database made by hand with the C locale:
                // everything restores and Russian search half-works without
                // an error anywhere (CLAUDE.md).
                $manifest['cyrillic_ok'] = (bool) DB::selectOne("select lower('Ж') = 'ж' as ok")->ok;
                // The installer writes its own lock as its last step: a
                // lock now would end the install it is in the middle of.
                $manifest['lock_written'] = $lock && app(\App\Core\Install\Installer::class)->lockAfterRestore();

                // public/storage is a link the installer made, in neither the
                // code nor a backup: on a new server every picture was a 404
                // (the restore drill, 2026-11-03).
                if (! app(FileStorage::class)->onS3() && ! file_exists(public_path('storage'))) {
                    $say('Linking public/storage to the uploaded files');
                    \Illuminate\Support\Facades\Artisan::call('storage:link');
                    $manifest['storage_linked'] = file_exists(public_path('storage'));
                }
            }

            return $manifest;
        } finally {
            $reader?->close();
            File::deleteDirectory($work);
        }
    }

    /**
     * Only the uploaded files put back from a backup — the full one it leans
     * on first, when it holds only changed files — and the database left
     * alone: what restore() does for the files, on its own.
     *
     * @param  callable(string): void|null  $say
     * @return array<string, mixed> the backup's manifest
     */
    public function restoreFiles(string $name, ?string $file = null, ?string $password = null, ?string $baseFile = null, ?callable $say = null, ?BackupDestination $from = null): array
    {
        $say ??= fn () => null;
        $work = $this->workDir();
        $reader = null;

        try {
            $reader = $this->open($name, $file, $password, $work, $say, $from);
            $manifest = [];
            $reader->each(function (string $entry, $stream) use (&$manifest) {
                if ($entry === 'manifest.json') {
                    $manifest = json_decode((string) stream_get_contents($stream), true) ?: [];

                    return false;
                }

                return true;
            });

            if (! in_array('files', $manifest['contents'] ?? [], true)) {
                throw new RuntimeException('The backup holds no files.');
            }

            $manifest['base_restored'] = false;
            if (! empty($manifest['files_since'])) {
                $this->restoreBase($manifest, $baseFile, $password, $work, $say, $from);
                $manifest['base_restored'] = true;
            }

            $say('Restoring the files');
            $this->restoreEntries($reader, true, false);

            return $manifest;
        } finally {
            $reader?->close();
            File::deleteDirectory($work);
        }
    }

    /**
     * The files of the full backup a backup of changed files leans on, put
     * back before its own: named by its manifest (`base`), or given as a
     * path on a new server whose journal knows nothing. Its database is not
     * touched — the differential carries the whole of it. Refused in words
     * when it cannot be found, or is not a full backup itself.
     */
    protected function restoreBase(array $manifest, ?string $baseFile, ?string $password, string $work, callable $say, ?BackupDestination $from): void
    {
        $since = (string) ($manifest['files_since'] ?? '');
        $baseName = $baseFile !== null ? basename(rtrim($baseFile, '/\\')) : (string) ($manifest['base'] ?? '');
        $hint = ' On a new server give that backup by its path: --base=/path/to/'.($baseName !== '' ? $baseName : 'full-backup.zip');

        if ($baseName === '') {
            throw new RuntimeException("This backup holds only the files changed since $since and does not name the full backup it leans on.".$hint);
        }

        $baseWork = $work.DIRECTORY_SEPARATOR.'base';
        File::ensureDirectoryExists($baseWork);
        $say("Restoring the files of the full backup $baseName first");

        try {
            $base = $this->open($baseName, $baseFile, $password, $baseWork, $say, $from);
        } catch (RuntimeException $e) {
            throw new RuntimeException("This backup holds only the files changed since $since; the full backup it leans on, $baseName, must go back first and could not be opened: ".$e->getMessage().$hint, 0, $e);
        }

        try {
            $this->restoreEntries($base, true, false, function (array $head) use ($baseName) {
                if (! empty($head['files_since'])) {
                    throw new RuntimeException("$baseName holds only changed files itself: it is not the full backup to lean on.");
                }

                if (! in_array('files', $head['contents'] ?? [], true)) {
                    throw new RuntimeException("$baseName holds no files: it is not the full backup to lean on.");
                }
            });
        } finally {
            $base->close();
        }
    }

    /**
     * A backup's files and code written where they belong: every
     * `files/<disk>/…` entry onto that disk, `code/modules|themes/…` over
     * the folders — never deleting what the backup lacks. `$head` is handed
     * the manifest, which comes first, before anything is written.
     *
     * @param  callable(array<string, mixed>): void|null  $head
     */
    protected function restoreEntries(Archive\Reader $reader, bool $files, bool $code, ?callable $head = null): void
    {
        $roots = ['code/modules/' => (string) config('torgash.modules_path'), 'code/themes/' => (string) config('torgash.themes_path')];

        $reader->each(function (string $entry, $stream) use ($files, $code, $roots, $head) {
            if ($head !== null && $entry === 'manifest.json') {
                $head(json_decode((string) stream_get_contents($stream), true) ?: []);
            }

            if ($files && preg_match('#^files/([a-z]+)/(.+)$#', $entry, $m) && in_array($m[1], FileStorage::DISKS, true)) {
                if (! in_array('..', explode('/', $m[2]), true)) {
                    Storage::disk($m[1])->writeStream($m[2], $stream);
                }
            }

            if ($code) {
                foreach ($roots as $prefix => $root) {
                    if (str_starts_with($entry, $prefix)) {
                        $this->writeInto($root, substr($entry, strlen($prefix)), $stream);
                    }
                }
            }

            return true;
        });
    }

    /**
     * The head of a backup on this server — its manifest, and the .env it
     * carries copied to `$envTo` when asked — without unpacking the rest:
     * what the installer reads before it writes anything (PLAN §67.8, 6).
     * An encrypted tar is decrypted whole to get there; the work folder goes
     * with it.
     *
     * @return array{manifest: array<string, mixed>, env: bool}
     */
    public function inspect(string $file, ?string $password = null, ?string $envTo = null): array
    {
        $work = $this->workDir();
        $reader = null;

        try {
            $reader = $this->open('', $file, $password, $work, fn () => null);
            $manifest = null;
            $env = false;

            $reader->each(function (string $entry, $stream) use (&$manifest, &$env, $envTo) {
                if ($entry === 'manifest.json') {
                    $manifest = json_decode((string) stream_get_contents($stream), true);
                    if (! is_array($manifest)) {
                        throw new RuntimeException('The backup\'s manifest.json cannot be read: wrong password, or the file is damaged.');
                    }

                    return true;
                }

                if ($entry === 'env/.env') {
                    $env = true;
                    if ($envTo !== null) {
                        $this->copyStream($stream, $envTo);
                        @chmod($envTo, 0600);
                    }

                    return true;
                }

                return false;
            });

            if ($manifest === null) {
                throw new RuntimeException('This is not a backup of Torgash: it has no manifest.json.');
            }

            return ['manifest' => $manifest, 'env' => $env];
        } finally {
            $reader?->close();
            File::deleteDirectory($work);
        }
    }

    /** Whether an application key (`base64:…` as in .env) is the one a backup was made with. */
    public function keyFits(array $manifest, string $appKey): ?bool
    {
        if (empty($manifest['key_check'])) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tk');
        file_put_contents($tmp, 'APP_KEY='.$appKey."\n");

        try {
            return $this->keyMatches((string) $manifest['key_check'], $tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * The backup as a reader: a file or folder named by its path, or found
     * through the journal (a folder copy is read where it is kept; a file is
     * fetched first). An encrypted tar is decrypted into the work folder.
     */
    protected function open(string $name, ?string $file, ?string $password, string $work, callable $say, ?BackupDestination $from = null): Archive\Reader
    {
        if ($file !== null) {
            if (is_dir($file)) {
                if (! is_file(rtrim($file, '/\\').DIRECTORY_SEPARATOR.'manifest.json')) {
                    throw new RuntimeException("The folder $file holds no manifest.json: not a backup, or one that never finished.");
                }

                return new Archive\FolderReader(new Store(Storage::build(['driver' => 'local', 'root' => $file, 'throw' => true])), '');
            }

            if (! is_file($file)) {
                throw new RuntimeException("There is no file $file.");
            }

            return $this->fileReader($file, $password, $work);
        }

        [$store, $path, $run] = $from
            ? $this->locateIn($from, $name) ?? throw new RuntimeException("There is no backup called $name in \"{$from->name}\".")
            : $this->locate($name) ?? throw new RuntimeException("There is no backup called $name.");

        if ($password === null && $run?->encrypted && $run->plan) {
            $password = $run->plan->password() ?: null;
        }

        if ($run?->isFolder() || $store->exists($path.'/manifest.json')) {
            return new Archive\FolderReader($store, $path);
        }

        $say('Fetching the backup');
        $local = $work.DIRECTORY_SEPARATOR.basename($path);
        $read = $store->read($path);
        $this->copyStream($read, $local);
        if (is_resource($read)) {
            fclose($read);
        }

        return $this->fileReader($local, $password, $work);
    }

    protected function fileReader(string $path, ?string $password, string $work): Archive\Reader
    {
        if ((string) file_get_contents($path, false, null, 0, 8) === 'Salted__') {
            if ($password === null || $password === '') {
                throw new RuntimeException('The backup is encrypted: give its password (--password).');
            }

            $plain = $work.DIRECTORY_SEPARATOR.'decrypted';
            Archive\Cipher::decryptFile($path, $plain, $password);
            $path = $plain;
        }

        return Archive\Reader::file($path, $password);
    }

    protected function restoreDump(string $dump, string $target): void
    {
        $list = $this->withoutExtensions($dump, $target);

        $this->pg('pg_restore', [
            '--clean', '--if-exists', '--no-owner', '--no-acl', '--single-transaction',
            '-L', $list,
            '-d', $target,
            $dump,
        ]);
    }

    /**
     * A plain-SQL dump through psql, in one transaction. Its extension lines
     * are left out for the same reason as in `withoutExtensions()` — `DROP
     * EXTENSION` is the superuser's — and the extensions created first.
     */
    protected function restoreSql(string $sql, string $target): void
    {
        $in = fopen($sql, 'rb');
        $filtered = dirname($sql).DIRECTORY_SEPARATOR.'restore.sql';
        $out = fopen($filtered, 'wb');
        $extensions = [];

        while (($line = fgets($in)) !== false) {
            if (preg_match('/^CREATE EXTENSION IF NOT EXISTS ("?)([A-Za-z0-9_]+)\1/', $line, $m)) {
                $extensions[] = $m[2];

                continue;
            }

            if (preg_match('/^(DROP EXTENSION|COMMENT ON EXTENSION)\b/', $line)) {
                continue;
            }

            fwrite($out, $line);
        }
        fclose($in);
        fclose($out);

        foreach ($extensions as $extension) {
            try {
                $this->pg('psql', ['-d', $target, '-c', 'create extension if not exists '.$extension]);
            } catch (Throwable) {
            }
        }

        $this->pg('psql', ['-v', 'ON_ERROR_STOP=1', '--single-transaction', '-q', '-d', $target, '-f', $filtered]);
    }

    /**
     * Writes one entry under $root — over what is there, never deleting what
     * is not in the backup. An entry that would climb out of $root is skipped.
     *
     * @param  resource  $stream
     */
    protected function writeInto(string $root, string $relative, $stream): bool
    {
        if ($root === '' || $relative === '' || in_array('..', explode('/', str_replace('\\', '/', $relative)), true)) {
            return false;
        }

        $target = rtrim($root, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($target));
        $this->copyStream($stream, $target);

        return true;
    }

    /** @param  resource  $from */
    protected function copyStream($from, string $to): void
    {
        $out = fopen($to, 'wb');
        if ($out === false) {
            throw new RuntimeException('Cannot write '.$to);
        }
        stream_copy_to_stream($from, $out);
        fclose($out);
    }

    /**
     * A short constant encrypted with APP_KEY when the backup was made: if
     * this server cannot read it back, its APP_KEY is another one. With the
     * backup's .env just put in place, its key is the one asked. Null when
     * the backup is older than the check.
     */
    protected function keyMatches(?string $check, ?string $envFile = null): ?bool
    {
        if (! $check) {
            return null;
        }

        try {
            if ($envFile !== null && preg_match('/^APP_KEY=(.*)$/m', (string) file_get_contents($envFile), $m)) {
                $key = trim($m[1], " \t\"'\r");
                $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;

                return (new \Illuminate\Encryption\Encrypter($key, (string) config('app.cipher')))->decryptString($check) === self::KEY_CHECK;
            }

            return Crypt::decryptString($check) === self::KEY_CHECK;
        } catch (Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------ PostgreSQL

    /** Every table of the public schema with its exact number of rows, in one statement. */
    protected const COUNT_ROWS = "select c.relname, (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from public.%I', c.relname), false, true, '')))[1]::text::bigint
        from pg_class c join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and c.relkind in ('r', 'p') and not c.relispartition";

    /**
     * A read-only transaction whose snapshot pg_dump is handed: its id, the
     * rows counted in it, and the connection holding it open (drop it once
     * pg_dump is done). [null, [], null] where it cannot be had — the dump
     * is then made as before, without counts.
     *
     * @return array{0: ?string, 1: array<string, int>, 2: ?\PDO}
     */
    protected function snapshot(): array
    {
        try {
            $pdo = $this->pdo();
            $pdo->exec('begin transaction isolation level repeatable read, read only');
            $id = (string) $pdo->query('select pg_export_snapshot()')->fetchColumn();
            $rows = [];
            foreach ($pdo->query(self::COUNT_ROWS)->fetchAll(\PDO::FETCH_KEY_PAIR) as $table => $count) {
                $rows[$table] = (int) $count;
            }
            ksort($rows);

            return [$id, $rows, $pdo];
        } catch (Throwable) {
            return [null, [], null];
        }
    }

    /** A connection of its own — outside Laravel's, and outside a test's transaction. */
    protected function pdo(?string $database = null): \PDO
    {
        $c = $this->connection();

        return new \PDO("pgsql:host={$c['host']};port={$c['port']};dbname=".($database ?? $c['database']), $c['username'], $c['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    /** The shop database's own locale, for a database made beside it (CLAUDE.md: never C). */
    protected function localeClause(\PDO $pdo): string
    {
        try {
            $row = $pdo->query('select datlocprovider, datcollate, datctype, datlocale as icu from pg_database where datname = current_database()')->fetch(\PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $row = $pdo->query('select datlocprovider, datcollate, datctype, daticulocale as icu from pg_database where datname = current_database()')->fetch(\PDO::FETCH_ASSOC);
        }

        $quote = fn (?string $v) => "'".str_replace("'", "''", (string) $v)."'";
        $clause = 'lc_collate '.$quote($row['datcollate']).' lc_ctype '.$quote($row['datctype']);

        return ($row['datlocprovider'] ?? '') === 'i'
            ? $clause.' locale_provider icu icu_locale '.$quote($row['icu'] ?: 'und')
            : $clause;
    }

    /** @return array{host: string, port: string, username: string, password: string, database: string} */
    protected function connection(): array
    {
        $c = (array) config('database.connections.'.config('database.default'));

        return [
            'host' => (string) ($c['host'] ?? '127.0.0.1'),
            'port' => (string) ($c['port'] ?? '5432'),
            'username' => (string) ($c['username'] ?? 'postgres'),
            'password' => (string) ($c['password'] ?? ''),
            'database' => (string) ($c['database'] ?? ''),
        ];
    }

    /**
     * The dump's table of contents with its extensions left out, as a file
     * for `pg_restore -L`.
     *
     * An extension (pg_trgm, which the search's indexes need) belongs to the
     * role that created it — on a real server `postgres`, not the shop's own
     * user — and `--clean` begins by dropping it: "must be owner of extension
     * pg_trgm", the whole single transaction refused, and the restore an
     * update's rollback depends on never happened (the first live shop,
     * 2026-10-22). The extensions are already in the shop's database; for
     * another database they are created first, if this role may.
     */
    protected function withoutExtensions(string $dump, string $database): string
    {
        $c = $this->connection();
        $result = Process::timeout(120)->run([self::binary('pg_restore'), '-l', $dump]);

        if (! $result->successful()) {
            throw new RuntimeException('pg_restore: '.Str::limit(trim($result->errorOutput()) ?: 'cannot read the dump', 800));
        }

        $kept = [];
        $extensions = [];

        foreach (preg_split('/\R/', $result->output()) as $line) {
            if (preg_match('/^\s*\d+;\s*\d+\s+\d+\s+EXTENSION\s+-\s+(\S+)/', $line, $m)) {
                $extensions[] = $m[1];

                continue;
            }

            if (preg_match('/\bCOMMENT\s+-\s+EXTENSION\b/', $line)) {
                continue;
            }

            $kept[] = $line;
        }

        // Created first in ANY target, the shop's own included: on a new
        // server after the old one is gone the shop's database is empty, and
        // without pg_trgm the search indexes fail and the whole restore with
        // them (the restore drill, 2026-11-03). Where it is there already
        // this is a no-op.
        foreach ($extensions as $extension) {
            try {
                $this->pg('psql', ['-d', $database, '-c', 'create extension if not exists '.preg_replace('/[^A-Za-z0-9_]/', '', $extension)]);
            } catch (Throwable) {
                // Not this role's to create: the restore says what is missing.
            }
        }

        $list = dirname($dump).DIRECTORY_SEPARATOR.'restore.list';
        file_put_contents($list, implode("\n", $kept)."\n");

        return $list;
    }

    /** The full path of pg_dump / pg_restore, or the bare name to find on the PATH. */
    public static function binary(string $tool): string
    {
        $dir = rtrim((string) config('torgash.pg_bin', ''), '/\\');

        if ($dir === '') {
            return $tool;
        }

        $path = $dir.DIRECTORY_SEPARATOR.$tool.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');

        return is_file($path) ? $path : $tool;
    }

    /** "pg_dump (PostgreSQL) 18.3", or null when it cannot be run. */
    public static function toolVersion(string $tool = 'pg_dump'): ?string
    {
        try {
            $result = Process::timeout(15)->run([self::binary($tool), '--version']);

            return $result->successful() ? trim($result->output()) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param  list<string>  $arguments */
    protected function pg(string $tool, array $arguments): void
    {
        $c = $this->connection();

        // Messages in English and the client in UTF-8: a Russian Windows
        // server answered in cp1251, and a console line that is not UTF-8 is
        // printed as NOTHING — the restore failed without a word.
        $result = Process::timeout(3600)
            ->env(['PGPASSWORD' => $c['password'], 'LC_MESSAGES' => 'C', 'PGCLIENTENCODING' => 'UTF8'])
            ->run([self::binary($tool), '-h', $c['host'], '-p', $c['port'], '-U', $c['username'], ...$arguments]);

        if (! $result->successful()) {
            throw new RuntimeException($tool.': '.Str::limit(self::readable(trim($result->errorOutput())) ?: 'exit code '.$result->exitCode(), 800));
        }
    }

    /** A tool's message as UTF-8 whatever it was written in. */
    protected static function readable(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        return mb_convert_encoding($text, 'UTF-8', 'Windows-1251');
    }

    // ------------------------------------------------------------ helpers

    /**
     * A folder's files as [entry name, path] under $prefix, skipping top-level
     * $skip folders.
     *
     * @return list<array{0: string, 1: string}>
     */
    /**
     * @param  list<string>  $skip  top folders left out
     * @param  ?int  $since  only files changed at or after this moment (a Unix time)
     * @param  bool  $thumbnails  false leaves out every `<id>/conversions/` folder
     * @return list<array{0: string, 1: string}>
     */
    protected function collectTree(string $root, string $prefix, array $skip = [], ?int $since = null, bool $thumbnails = true): array
    {
        if ($root === '' || ! is_dir($root)) {
            return [];
        }

        $root = rtrim(realpath($root) ?: $root, '/\\');
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (in_array(strtok($relative, '/'), $skip, true)) {
                continue;
            }

            // medialibrary: <media id>/conversions/<name>-<conversion>.jpg
            if (! $thumbnails && (explode('/', $relative)[1] ?? null) === self::CONVERSIONS) {
                continue;
            }

            if ($since !== null && $file->getMTime() < $since) {
                continue;
            }

            $entries[] = [$prefix.'/'.$relative, $file->getPathname()];
        }

        return $entries;
    }

    protected function workDir(): string
    {
        $dir = storage_path('app/backup-tmp/'.Str::uuid());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * Every moment of every enabled plan, in the shop's clock; and once a
     * minute a look for runs left waiting (one queued while the task was just
     * finishing would otherwise wait for the next button).
     */
    public static function schedule(Schedule $schedule): void
    {
        try {
            if (! Schema::hasTable('backup_plans')) {
                return;
            }

            $plans = BackupPlan::query()->where('enabled', true)->get();
        } catch (Throwable) {
            return;
        }

        foreach ($plans as $plan) {
            foreach (array_values((array) $plan->schedule) as $i => $entry) {
                $cron = PlanSchedule::cron((array) $entry);

                if ($cron === null) {
                    continue;
                }

                $id = $plan->id;
                $schedule->call(fn () => app(self::class)->queueById($id, 'schedule'))
                    ->cron($cron)->timezone((string) config('app.timezone'))->name("core:backup:$id:$i");
            }
        }

        foreach ($plans as $plan) {
            $cron = match ($plan->option('verify')) {
                'weekly' => '20 5 * * 0',
                'monthly' => '20 5 1 * *',
                default => null,
            };

            if ($cron !== null) {
                $id = $plan->id;
                $schedule->call(fn () => VerifyBackup::startIfIdle($id))
                    ->cron($cron)->timezone((string) config('app.timezone'))->name("core:backup-verify:$id");
            }
        }

        $schedule->call(function () {
            if (BackupRun::query()->where('status', BackupRun::QUEUED)->exists()) {
                CreateBackup::startIfIdle();
            }
        })->everyMinute()->name('core:backup-queue');

        // Silence is the one failure that writes no error: once a day, a
        // letter for every plan whose schedule has gone without a backup.
        $schedule->call(function () {
            foreach (app(BackupHealth::class)->attention()->where('problem', 'stale') as $item) {
                app(self::class)->tell(null, 'stale', $item['reason'], $item['plan']);
            }
        })->dailyAt('09:10')->timezone((string) config('app.timezone'))->name('core:backup-overdue');
    }
}
