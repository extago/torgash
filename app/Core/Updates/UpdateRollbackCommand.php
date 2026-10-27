<?php

namespace App\Core\Updates;

use Illuminate\Console\Command;

/** `torgash:update-rollback` — put the previous version back: code, then the database from the backup made before the update. */
class UpdateRollbackCommand extends Command
{
    protected $signature = 'torgash:update-rollback {--force : Do not ask}';

    protected $description = 'Undo the last update: the previous code and the database as it was before it';

    public function handle(): int
    {
        $updater = new Updater;
        $plan = $updater->lastPlan();
        if ($plan === null || ! is_file($updater->rollbackScript())) {
            $this->warn('No update to undo.');

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($plan), true);
        if (! $this->option('force') && ! $this->confirm("Go back from {$data['to']} to {$data['from']}? Orders placed since the update are lost with the database.")) {
            return self::FAILURE;
        }

        $this->line("If this command itself fails, run: php {$updater->rollbackScript()} $plan");

        return $updater->rollback($plan, say: fn (string $line) => $this->line($line)) ? self::SUCCESS : self::FAILURE;
    }
}
