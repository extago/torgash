<?php

namespace App\Core\Tasks;

use App\Models\BackgroundTask;
use Illuminate\Support\Facades\DB;

/**
 * What the shop can say about its background work beyond one row: stopping a
 * task, recognising one whose worker is gone, and whether anybody is taking
 * jobs off the queue at all. The screen, the bell and `TrackedJob::startIfIdle()`
 * all ask here, so "stuck" means one thing everywhere.
 */
class Tasks
{
    /**
     * A queued job nobody has picked up for this long means no worker is
     * running — a worker polls every few seconds.
     */
    public const WORKER_SILENT_AFTER_SECONDS = 60;

    /**
     * Stop a task. One that has not started, or whose worker is gone, is
     * stopped here and now (its job is taken off the queue); one that is
     * really running is ASKED to stop, and stops at its next step — a worker
     * cannot be interrupted from outside, and an import cut mid-line is worse
     * than one that finishes the line.
     *
     * @return bool true when it stopped now, false when it was asked to
     */
    public function cancel(BackgroundTask $task, ?string $by = null): bool
    {
        if (! $task->isActive()) {
            return true;
        }

        if ($task->status === BackgroundTask::RUNNING && ! $task->isStale()) {
            $task->update(['cancel_requested_at' => now()]);

            return false;
        }

        $this->forgetQueuedJob($task);

        $task->update([
            'status' => BackgroundTask::CANCELLED,
            'finished_at' => now(),
            'cancel_requested_at' => $task->cancel_requested_at ?? now(),
            'message' => $by !== null ? 'Cancelled by :name' : 'Cancelled before it finished',
            'message_params' => $by !== null ? ['name' => $by] : null,
        ]);

        return true;
    }

    /**
     * Rows that say "running" but whose worker died, written off so they stop
     * claiming to be active — the reason a nightly `startIfIdle()` job could
     * otherwise never run again after one crash.
     */
    public function reapStale(?string $name = null, ?string $module = null): int
    {
        $stale = BackgroundTask::query()
            ->where('status', BackgroundTask::RUNNING)
            ->where('updated_at', '<', now()->subSeconds(BackgroundTask::STALE_AFTER_SECONDS))
            ->when($name !== null, fn ($q) => $q->where('name', $name)->where('module', $module))
            ->get();

        foreach ($stale as $task) {
            $task->update([
                'status' => BackgroundTask::CANCELLED,
                'finished_at' => now(),
                'message' => 'The worker running it stopped; it did not finish.',
                'message_params' => null,
            ]);
        }

        return $stale->count();
    }

    /** Jobs waiting in the queue. */
    public function pending(): int
    {
        return DB::table('jobs')->count();
    }

    /**
     * How long the oldest job that is due has waited unclaimed, in seconds —
     * or null when nothing is waiting. Asked of the queue itself, so it covers
     * an order email as much as a tracked task.
     */
    public function oldestWait(): ?int
    {
        $availableAt = DB::table('jobs')->whereNull('reserved_at')
            ->where('available_at', '<=', now()->getTimestamp())
            ->min('available_at');

        return $availableAt === null ? null : max(0, now()->getTimestamp() - (int) $availableAt);
    }

    /** Nobody is taking jobs off the queue: something due has waited past a worker's polling. */
    public function workerSilent(): bool
    {
        return ($this->oldestWait() ?? 0) > self::WORKER_SILENT_AFTER_SECONDS;
    }

    public function failedJobs(): int
    {
        return DB::table('failed_jobs')->count();
    }

    public function staleCount(): int
    {
        return BackgroundTask::query()
            ->where('status', BackgroundTask::RUNNING)
            ->where('updated_at', '<', now()->subSeconds(BackgroundTask::STALE_AFTER_SECONDS))
            ->count();
    }

    /**
     * Take a queued task's job off the queue. The row knows its task only
     * through the serialized job (`taskId` is a public property), so the
     * payload is read — narrowed first to payloads that mention one.
     */
    protected function forgetQueuedJob(BackgroundTask $task): void
    {
        $needle = 's:6:"taskId";i:'.$task->id.';';

        DB::table('jobs')->where('payload', 'like', '%taskId%')->orderBy('id')
            ->each(function ($job) use ($needle) {
                $command = json_decode($job->payload, true)['data']['command'] ?? '';

                if (is_string($command) && str_contains($command, $needle)) {
                    DB::table('jobs')->where('id', $job->id)->delete();
                }
            });
    }
}
