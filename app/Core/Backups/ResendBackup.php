<?php

namespace App\Core\Backups;

use App\Core\Tasks\TrackedJob;
use App\Models\BackupCopy;
use RuntimeException;
use Throwable;

/**
 * Sends a backup to a storage that did not take it the first time (PLAN
 * §67.11) — from a copy that was stored, without making the backup again.
 * A tracked task, as the making is: the file may be gigabytes, and the
 * outcome belongs on the Background tasks screen as well as on the run's row.
 */
class ResendBackup extends TrackedJob
{
    public function __construct(public int $copyId) {}

    public function label(): string
    {
        return 'Send a backup to its storage again';
    }

    protected function run(): void
    {
        $copy = BackupCopy::query()->with(['run', 'destination'])->find($this->copyId);

        if (! $copy || $copy->status !== BackupCopy::FAILED) {
            return;
        }

        try {
            $copy = app(Backups::class)->resend($copy, fn (string $message, array $params) => $this->message($message, $params));
        } catch (Throwable $e) {
            throw new RuntimeException(Destinations::reason($e), 0, $e);
        }

        $this->result(['name' => $copy->run->name], 'Backup :name is in :storage', ['name' => $copy->run->name, 'storage' => $copy->destination->name]);
    }
}
