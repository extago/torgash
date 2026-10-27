<?php

namespace App\Core\Tasks;

use App\Models\BackgroundTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Base class for work that is too slow to run inside a request.
 *
 * A subclass implements label() and run(); everything else — the tracking row,
 * progress, the failure record the admin can actually read — comes from here,
 * so a module never has to invent its own "is it done yet" table.
 *
 *     class RebuildThumbnails extends TrackedJob
 *     {
 *         public function label(): string { return 'Rebuild thumbnails'; }
 *         public function module(): ?string { return 'torgash.media'; }
 *         protected function run(): void
 *         {
 *             $this->total(Media::count());
 *             foreach (Media::cursor() as $i => $media) { ...; $this->progress($i + 1); }
 *         }
 *     }
 *
 *     RebuildThumbnails::start();   // creates the row, pushes the job
 */
abstract class TrackedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** id of the tracking row; public so it survives job serialization. */
    public ?int $taskId = null;

    /**
     * One attempt. A retry of a half-finished bulk job redoes work whose side
     * effects already landed, and the admin can always press the button again
     * once they have read the error.
     */
    public int $tries = 1;

    /** Bulk work legitimately runs for minutes; the queue default is 60s. */
    public int $timeout = 3600;

    /**
     * Seconds a run may spend before it should hand over to a continuation
     * (`timeIsUp()` / `continueWith()`), well inside $timeout: a job over its
     * timeout is killed mid-item, and a library only grows.
     */
    public int $budget = 2700;

    /** Set by continueWith(): the row stays running for the next part. */
    protected bool $continued = false;

    /** When this part started, for timeIsUp(). */
    protected float $startedAt = 0.0;

    /** Milliseconds of the last progress write, to throttle updates. */
    protected int $lastWrite = 0;

    /** Milliseconds of the last look at the row for "stop this", to throttle reads. */
    protected int $lastCancelCheck = 0;

    /** Human name of the task, in English — the admin list runs it through __(). */
    abstract public function label(): string;

    /** Work to perform. Call total()/progress() to report on the way. */
    abstract protected function run(): void;

    /** Module id this task belongs to, or null for core. */
    public function module(): ?string
    {
        return null;
    }

    /**
     * Creates the tracking row and pushes the job. Arguments are handed to the
     * subclass constructor, so callers write RebuildThumbnails::start($arg).
     */
    public static function start(mixed ...$args): BackgroundTask
    {
        /** @var static $job */
        $job = new static(...$args);

        return $job->dispatchTracked();
    }

    /**
     * start(), but a no-op returning null when an instance of this job is
     * already queued or running. For work put on the scheduler: a nightly
     * "continue where you left off" job must not stack a second copy on top of
     * one still going (the queue has no worker watching that for us).
     *
     * Keyed by label()+module(), which is what the tracking row records — every
     * TrackedJob in a module has a distinct label. A row still saying "running"
     * whose worker died is written off first (Tasks::reapStale()): otherwise
     * one crash would stop the job from ever being scheduled again.
     */
    public static function startIfIdle(mixed ...$args): ?BackgroundTask
    {
        /** @var static $job */
        $job = new static(...$args);

        app(Tasks::class)->reapStale($job->label(), $job->module());

        $active = BackgroundTask::where('name', $job->label())
            ->where('module', $job->module())
            ->whereIn('status', [BackgroundTask::QUEUED, BackgroundTask::RUNNING])
            ->exists();

        return $active ? null : $job->dispatchTracked();
    }

    /** Writes the tracking row for this job instance and pushes it. */
    protected function dispatchTracked(): BackgroundTask
    {
        $task = BackgroundTask::create([
            'name' => $this->label(),
            'module' => $this->module(),
            'status' => BackgroundTask::QUEUED,
            'user_id' => auth()->id(),
        ]);

        $this->taskId = $task->id;
        dispatch($this);

        return $task;
    }

    public function handle(): void
    {
        // Cancelled while it waited: the row already says so, and its job
        // is normally gone from the queue too — this is the race where a
        // worker had claimed it a moment before.
        if ($this->task()?->status === BackgroundTask::CANCELLED) {
            return;
        }

        $this->startedAt = microtime(true);
        // A continuation keeps the first part's start, so the duration shown
        // is the whole task's.
        $this->write(['status' => BackgroundTask::RUNNING] + ($this->task()?->started_at ? [] : ['started_at' => now()]));

        // What it writes is the act of whoever started it (the audit asks).
        app(CurrentTask::class)->enter($this->task());

        try {
            $this->stopIfAsked(force: true);
            $this->run();
        } catch (TaskCancelled) {
            $this->write([
                'status' => BackgroundTask::CANCELLED,
                'finished_at' => now(),
                'message' => 'Stopped on request',
                'message_params' => null,
            ]);

            return;
        } catch (Throwable $e) {
            $this->markFailed($e);

            // Rethrown so the worker also records it in failed_jobs: the admin
            // screen shows the message, that table keeps the full payload.
            throw $e;
        } finally {
            app(CurrentTask::class)->leave();
        }

        if ($this->continued) {
            return;
        }

        $task = $this->task();

        $this->write([
            'status' => BackgroundTask::DONE,
            'finished_at' => now(),
            'progress' => $task?->total ?? $task?->progress ?? 0,
        ]);
    }

    /**
     * Called by the worker when the last attempt failed — including failures
     * handle() never sees, such as the job timing out or the payload failing to
     * unserialize because a model it referenced was deleted.
     */
    public function failed(?Throwable $e): void
    {
        $this->markFailed($e);
    }

    /** True once this part has spent its $budget — time to continueWith(). */
    protected function timeIsUp(): bool
    {
        return microtime(true) - $this->startedAt > $this->budget;
    }

    /**
     * Hands the rest of the work to $next — a new job on the same tracking
     * row, carrying whatever it needs to resume (the last id done, counts so
     * far). This part then ends without marking the task done. How a bulk
     * job outlives the queue's timeout without being killed mid-item.
     */
    protected function continueWith(self $next): void
    {
        $next->taskId = $this->taskId;
        $this->continued = true;
        dispatch($next);
    }

    /** Declares how many items the run will process, enabling a real progress bar. */
    protected function total(int $total): void
    {
        $this->write(['total' => $total]);
    }

    /**
     * Reports progress. Throttled to one write per 500 ms: a row update per
     * processed item can easily cost more than the work being tracked.
     */
    protected function progress(int $done): void
    {
        $now = (int) (microtime(true) * 1000);

        if ($now - $this->lastWrite < 500) {
            return;
        }

        $this->lastWrite = $now;
        $this->write(['progress' => $done]);
        $this->stopIfAsked();
    }

    /**
     * Ends the run here when the admin asked it to stop (the row's
     * `cancel_requested_at`). progress() calls it on its own; a job that
     * reports no progress calls it between its steps. At most one read every
     * two seconds unless forced.
     *
     * @throws TaskCancelled
     */
    protected function stopIfAsked(bool $force = false): void
    {
        if (! $this->taskId) {
            return;
        }

        $now = (int) (microtime(true) * 1000);

        if (! $force && $now - $this->lastCancelCheck < 2000) {
            return;
        }

        $this->lastCancelCheck = $now;

        if (DB::table('background_tasks')->where('id', $this->taskId)->value('cancel_requested_at') !== null) {
            throw new TaskCancelled;
        }
    }

    /**
     * A line of context shown under the progress bar.
     *
     * Takes an untranslated string and its placeholders, NOT a finished
     * sentence: the worker runs in the application's default locale, so a
     * message translated here would reach a Russian admin in English. The list
     * renders it with __() at display time instead.
     */
    protected function message(string $message, array $params = []): void
    {
        $this->write([
            'message' => $message,
            'message_params' => $params ? json_encode($params) : null,
        ]);
    }

    /** Result summary shown next to the finished task (counts, totals, ...). */
    protected function result(array $result, ?string $message = null, array $params = []): void
    {
        $this->write(array_filter([
            'result' => json_encode($result),
            'message' => $message,
            'message_params' => $message && $params ? json_encode($params) : null,
        ], fn ($value) => $value !== null));
    }

    protected function task(): ?BackgroundTask
    {
        return $this->taskId ? BackgroundTask::find($this->taskId) : null;
    }

    protected function markFailed(?Throwable $e): void
    {
        $this->write([
            'status' => BackgroundTask::FAILED,
            'finished_at' => now(),
            'error' => $e ? mb_substr($e->getMessage(), 0, 2000) : 'Job failed without an exception.',
        ]);
    }

    /**
     * Updates go through the query builder rather than a loaded model: progress
     * is written from inside tight loops, and a fetch-modify-save per tick would
     * add a read to every write for no benefit.
     */
    protected function write(array $attributes): void
    {
        if (! $this->taskId) {
            return;
        }

        DB::table('background_tasks')
            ->where('id', $this->taskId)
            ->update($attributes + ['updated_at' => now()]);
    }
}
