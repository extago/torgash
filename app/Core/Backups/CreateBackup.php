<?php

namespace App\Core\Backups;

use App\Core\Tasks\TrackedJob;

/**
 * A backup made in the background (PLAN §45) — by the button on Admin →
 * System, or by the schedule. A tracked task, so a failure (no pg_dump, a full
 * disk) is on the Background tasks screen in words, not in a log.
 */
class CreateBackup extends TrackedJob
{
    public function label(): string
    {
        return 'Make a backup';
    }

    protected function run(): void
    {
        $name = app(Backups::class)->create(fn (string $message, array $params) => $this->message($message, $params));

        $this->result(['name' => $name], 'Backup :name made', ['name' => $name]);
    }
}
