<?php

namespace App\Core\Tasks;

use Illuminate\Console\Command;

/** `torgash:jobs-prune-missing` — forget failed jobs whose class no longer exists: they can never be retried (PLAN §45.6). */
class PruneMissingJobsCommand extends Command
{
    protected $signature = 'torgash:jobs-prune-missing';

    protected $description = 'Delete failed jobs whose code is gone (an uninstalled module, a removed class)';

    public function handle(OrphanJobs $orphans): int
    {
        $this->info($orphans->pruneMissingFailed().' failed job(s) without code removed.');

        return self::SUCCESS;
    }
}
