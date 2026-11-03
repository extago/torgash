<?php

namespace App\Core\Backups;

use App\Models\BackupPlan;
use App\Models\BackupRun;
use Illuminate\Console\Command;

/**
 * `torgash:backup` — a backup from the console, now, without a queue worker
 * (PLAN §45, §67): a plan's run made right here. Without --plan, the first
 * enabled plan.
 */
class BackupCommand extends Command
{
    protected $signature = 'torgash:backup
        {--plan= : The plan, by its code (e.g. main) or id}
        {--no-files : Only the database}
        {--with-code : Add the modules and themes}';

    protected $description = 'Make a backup of the shop by one of its plans: the database, the uploaded files and, if asked, the modules and themes';

    public function handle(Backups $backups): int
    {
        $wanted = (string) $this->option('plan');
        $plan = $wanted === ''
            ? BackupPlan::query()->where('enabled', true)->orderBy('id')->first()
            : BackupPlan::query()->where('code', $wanted)->orWhere('id', ctype_digit($wanted) ? (int) $wanted : 0)->first();

        if (! $plan) {
            $this->error($wanted === '' ? 'There is no enabled backup plan.' : "There is no backup plan \"$wanted\".");

            return self::FAILURE;
        }

        $contents = $plan->contents ?: ['database'];
        if ($this->option('no-files')) {
            $contents = array_values(array_diff($contents, ['files']));
        }
        if ($this->option('with-code')) {
            $contents = array_values(array_unique([...$contents, 'code']));
        }

        $run = $backups->runNow($plan, 'console', $contents, fn (string $message, array $params) => $this->line('  '.strtr($message, collect($params)->mapWithKeys(fn ($v, $k) => [':'.$k => $v])->all())));

        if ($run->status === BackupRun::FAILED) {
            $this->error((string) $run->error);

            return self::FAILURE;
        }

        if ($run->status === BackupRun::PARTIAL) {
            $this->warn("Backup {$run->name} made, but not everywhere:\n".$run->error);

            return self::FAILURE;
        }

        $where = $run->storedCopies()->with('destination')->get()->map(fn ($c) => $c->destination?->name)->filter()->implode(', ');
        $this->info("Backup {$run->name} made by the plan \"{$plan->name}\" ($where).");

        return self::SUCCESS;
    }
}
