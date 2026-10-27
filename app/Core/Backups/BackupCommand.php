<?php

namespace App\Core\Backups;

use Illuminate\Console\Command;
use Throwable;

/** `torgash:backup` — a backup from the console, now, without a queue worker (PLAN §45). */
class BackupCommand extends Command
{
    protected $signature = 'torgash:backup {--no-files : Only the database} {--with-code : Add the modules and themes}';

    protected $description = 'Make a backup of the shop: the database, the uploaded files and, if asked, the modules and themes';

    public function handle(Backups $backups): int
    {
        try {
            $name = $backups->create(
                fn (string $message, array $params) => $this->line('  '.strtr($message, collect($params)->mapWithKeys(fn ($v, $k) => [':'.$k => $v])->all())),
                $this->option('no-files') ? false : null,
                $this->option('with-code') ? true : null,
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup $name made on the \"{$backups->diskName()}\" disk.");

        return self::SUCCESS;
    }
}
