<?php

namespace App\Core\Backups;

use App\Core\Tasks\TaskRefused;
use App\Core\Tasks\TrackedJob;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use RuntimeException;

/**
 * Checks a backup by restoring it (PLAN §67.6): the newest of a plan by its
 * schedule (`verify` weekly / monthly), or one run picked on the list. A
 * tracked task — a big database takes a while to restore, and the outcome
 * belongs on the Background tasks screen as well as on the run's row.
 */
class VerifyBackup extends TrackedJob
{
    public function __construct(public ?int $planId = null, public ?int $runId = null) {}

    public function label(): string
    {
        return 'Check a backup by restoring it';
    }

    protected function run(): void
    {
        $backups = app(Backups::class);

        $run = $this->runId
            ? BackupRun::find($this->runId)
            : (($plan = BackupPlan::find($this->planId)) ? $backups->latestToVerify($plan) : null);

        if (! $run) {
            return;
        }

        $run = $backups->verify($run, fn (string $message) => $this->message($message));

        if ($run->verify_status === 'blocked') {
            throw new TaskRefused((string) $run->verify_error);
        }

        if ($run->verify_status !== 'passed') {
            throw new RuntimeException((string) $run->verify_error);
        }

        $this->result(['name' => $run->name], 'Backup :name restores', ['name' => $run->name]);
    }
}
