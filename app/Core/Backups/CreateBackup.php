<?php

namespace App\Core\Backups;

use App\Core\Tasks\TrackedJob;
use App\Models\BackupRun;
use RuntimeException;

/**
 * Makes the queued backups, one at a time and oldest first (PLAN §45, §67) —
 * queued by the button on Admin → System → Backups or by a plan's schedule.
 * Each run is a part of its own (`continueWith`), so a second big backup gets
 * a whole timeout of its own. A tracked task, so a failure (no pg_dump, a
 * full disk) is on the Background tasks screen in words, not in a log; the
 * run's own row says the same in the backups' list.
 */
class CreateBackup extends TrackedJob
{
    public function label(): string
    {
        return 'Make a backup';
    }

    protected function run(): void
    {
        $backups = app(Backups::class);
        $run = BackupRun::query()->where('status', BackupRun::QUEUED)->orderBy('id')->first();

        if (! $run) {
            return;
        }

        $run->update(['task_id' => $this->taskId]);
        $run = $backups->perform($run, fn (string $message, array $params) => $this->message($message, $params));

        if (BackupRun::query()->where('status', BackupRun::QUEUED)->exists()) {
            $this->continueWith(new static);

            return;
        }

        if ($run->status === BackupRun::FAILED) {
            throw new RuntimeException((string) $run->error);
        }

        $this->result(['name' => $run->name], 'Backup :name made', ['name' => $run->name]);
    }
}
