<?php

namespace App\Http\Controllers\Admin;

use App\Core\Backups\Backups;
use App\Http\Controllers\Controller;
use App\Models\BackgroundTask;
use App\Models\BackupCopy;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use Illuminate\Http\Request;
use Throwable;

/**
 * Admin → System → Backups (PLAN §45, §67): the journal of backups made, the
 * button that makes one by a plan, downloading, pinning, deleting. The way
 * back stays the console (`torgash:backup-restore`).
 */
class BackupsController extends Controller
{
    public function index(Backups $backups)
    {
        $backups->reapStale();

        return view('admin.system.backups', [
            'runs' => BackupRun::query()->with(['plan', 'copies.destination'])->orderByDesc('id')->paginate(50),
            'plans' => BackupPlan::query()->where('enabled', true)->orderBy('id')->get(),
            'pgDump' => Backups::toolVersion('pg_dump'),
            'running' => BackupRun::query()->whereIn('status', [BackupRun::QUEUED, BackupRun::RUNNING])->exists()
                || BackgroundTask::query()->where('name', 'Make a backup')->whereIn('status', [BackgroundTask::QUEUED, BackgroundTask::RUNNING])->exists(),
            'onS3' => app(\App\Core\Files\FileStorage::class)->onS3(),
        ]);
    }

    /** "Make a backup now" — by the plan chosen (the only one, when there is one). */
    public function store(Request $request, Backups $backups)
    {
        $plan = BackupPlan::query()->where('enabled', true)
            ->when($request->filled('plan'), fn ($q) => $q->whereKey((int) $request->input('plan')))
            ->orderBy('id')->first();

        if (! $plan) {
            return back()->withErrors(['plan' => __('There is no enabled backup plan. Make one on the "Backup plans" tab.')]);
        }

        $run = $backups->queue($plan, 'manual', $request->user()?->id);

        return back()->with('status', $run
            ? __('The backup has started. It appears in the list when it is done — it needs a queue worker.')
            : __('A backup by the plan ":plan" is already waiting or being made.', ['plan' => $plan->name]));
    }

    public function download(Backups $backups, BackupCopy $copy)
    {
        abort_unless($copy->status === BackupCopy::STORED && $copy->destination && $copy->run?->name, 404);
        // A folder is not one file to hand over: it is taken from the storage itself.
        abort_if($copy->run->isFolder(), 404);

        try {
            $store = $backups->store($copy->destination);
            abort_unless($store->exists((string) $copy->path), 404);
            $stream = $store->read((string) $copy->path);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            return back()->withErrors(['download' => __('The storage ":storage" cannot hand the backup back: :error', ['storage' => $copy->destination->name, 'error' => $e->getMessage()])]);
        }

        return response()->streamDownload(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, (string) $copy->run->name, ['Content-Type' => $copy->run->format === 'zip' ? 'application/zip' : 'application/octet-stream']);
    }

    /** "Check by restoring" from the list: a background task (PLAN §67.6). */
    public function verify(BackupRun $run)
    {
        abort_unless(in_array($run->status, [BackupRun::DONE, BackupRun::PARTIAL], true) && $run->storedCopies()->exists(), 404);

        $task = \App\Core\Backups\VerifyBackup::startIfIdle(null, $run->id);

        return back()->with('status', $task
            ? __('The check has started: the backup is restored into a database of its own and its rows compared. The result appears in the list — it needs a queue worker.')
            : __('Another backup is being checked: try again when it is done.'));
    }

    public function pin(BackupRun $run)
    {
        $run->update(['pinned' => ! $run->pinned]);

        return back()->with('status', $run->pinned
            ? __('The backup is pinned: keeping N never deletes it.')
            : __('The backup is no longer pinned.'));
    }

    public function destroy(Backups $backups, BackupRun $run)
    {
        abort_if(in_array($run->status, [BackupRun::QUEUED, BackupRun::RUNNING], true), 409);

        $problems = $backups->deleteRun($run);

        return $problems
            ? back()->withErrors(['delete' => __('Not deleted everywhere: :problems', ['problems' => implode('; ', $problems)])])
            : back()->with('status', __('Backup deleted.'));
    }
}
