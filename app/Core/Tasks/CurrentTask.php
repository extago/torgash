<?php

namespace App\Core\Tasks;

use App\Models\BackgroundTask;

/**
 * The tracked task this process is running right now, if any — set by
 * `TrackedJob::handle()` around `run()` and cleared after it.
 *
 * Whoever wants to know "is this write somebody's background work" asks
 * here: the audit records a renumbering or an import as an act of the person
 * who pressed the button, with the task beside it, instead of not at all
 * (a queue worker has no request and no signed-in user). Entered and left
 * explicitly, in a `finally`, so a worker that runs the next job never sees
 * the last one's task.
 */
class CurrentTask
{
    protected ?BackgroundTask $task = null;

    public function enter(?BackgroundTask $task): void
    {
        $this->task = $task;
    }

    public function leave(): void
    {
        $this->task = null;
    }

    public function task(): ?BackgroundTask
    {
        return $this->task;
    }
}
