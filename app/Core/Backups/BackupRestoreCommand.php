<?php

namespace App\Core\Backups;

use Illuminate\Console\Command;
use Throwable;

/**
 * `torgash:backup-restore` — the way back (PLAN §45), from the console on
 * purpose: when an update has broken the admin, a button in the admin is a
 * door that is already shut.
 *
 * Without a name it lists the backups. `--database=` restores into another,
 * existing database — to check that a backup really restores without touching
 * the shop.
 */
class BackupRestoreCommand extends Command
{
    protected $signature = 'torgash:backup-restore {name? : The backup, e.g. torgash-2026-10-01-034000.zip}
        {--database= : Restore the database into this other (existing) database instead}
        {--no-files : Do not put the files back}
        {--force : Do not ask}';

    protected $description = 'Restore the shop from a backup (lists the backups when no name is given)';

    public function handle(Backups $backups): int
    {
        $name = (string) $this->argument('name');

        if ($name === '') {
            $rows = $backups->all()->map(fn ($b) => [$b['name'], number_format($b['bytes'] / 1048576, 1).' MB', date('Y-m-d H:i', $b['at'])])->all();
            $rows ? $this->table(['Backup', 'Size', 'Made'], $rows) : $this->warn('No backups on the "'.$backups->diskName().'" disk.');

            return self::SUCCESS;
        }

        $database = $this->option('database') ?: null;

        if (! $database && ! $this->option('force')
            && ! $this->confirm("Replace THIS shop's database".($this->option('no-files') ? '' : ' and files')." with $name? Everything since then is lost.")) {
            return self::FAILURE;
        }

        try {
            $manifest = $backups->restore($name, $database, ! $this->option('no-files'), fn (string $message) => $this->line('  '.$message));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Restored $name (made ".($manifest['created_at'] ?? '?').', engine '.($manifest['engine'] ?? '?').').');

        if (! $database) {
            $this->line('Run "php artisan migrate --force" if the code is newer than the backup, then "php artisan cache:clear".');
        }

        return self::SUCCESS;
    }
}
