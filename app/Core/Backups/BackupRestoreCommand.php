<?php

namespace App\Core\Backups;

use Illuminate\Console\Command;
use Throwable;

/**
 * `torgash:backup-restore` — the way back (PLAN §45, §67), from the console on
 * purpose: when an update has broken the admin, a button in the admin is a
 * door that is already shut.
 *
 * Without a name it lists the backups — from the journal, or, when the
 * database cannot be asked, from the server's backup folders. A backup kept
 * in another storage (a bucket) is fetched from there. `--file=` takes a zip
 * from a path instead: a NEW server after the old one is gone, whose journal
 * is empty. `--database=` restores into another, existing database — to check
 * that a backup really restores without touching the shop. `--code` puts the
 * modules and themes back too, when the backup has them.
 *
 * Any form a plan makes (§67.3): zip, tar, tar.gz, encrypted, or a folder
 * (`--file=` names the folder). An encrypted backup takes `--password=`;
 * without it the journal's plan gives its own when it can, and the console
 * asks. `--with-env` puts the backup's .env in place (encrypted backups carry it
 * when the plan says so) — on a new server, the APP_KEY that reads the rest.
 *
 * A backup of only the files changed since a full one (PLAN §67.10) puts
 * that full backup's files back first — found through the journal or the
 * storage, or named by `--base=` on a new server.
 */
class BackupRestoreCommand extends Command
{
    protected $signature = 'torgash:backup-restore {name? : The backup, e.g. torgash-local-main-2026-11-02-034000.zip}
        {--destination= : Look in this storage (its name or number) by reading the storage itself, not the journal}
        {--file= : A backup on this server, by its path: a file, or the folder of one made without an archive (a new server: the journal is empty)}
        {--password= : The password of an encrypted backup (asked when it is needed and not given)}
        {--with-env : Put the .env the backup carries in place too, when it carries one (the current one is kept as .env.before-restore)}
        {--database= : Restore the database into this other (existing) database instead}
        {--no-files : Do not put the uploaded files back}
        {--code : Put the modules and themes back too, when the backup has them}
        {--base= : For a backup of only the changed files: the full backup it leans on, by its path (a new server; otherwise it is found by itself)}
        {--force : Do not ask}';

    protected $description = 'Restore the shop from a backup (lists the backups when no name is given)';

    public function handle(Backups $backups): int
    {
        $file = $this->option('file') ? (string) $this->option('file') : null;
        $name = $file !== null ? basename($file) : (string) $this->argument('name');

        $from = null;
        if ($this->option('destination')) {
            $key = (string) $this->option('destination');
            $from = \App\Models\BackupDestination::query()->where(ctype_digit($key) ? 'id' : 'name', ctype_digit($key) ? (int) $key : $key)->first();

            if (! $from) {
                $this->error("There is no storage \"$key\". The storages: ".\App\Models\BackupDestination::query()->orderBy('id')->get()->map(fn ($d) => $d->id.' '.$d->name)->implode(', '));

                return self::FAILURE;
            }

            if ($name === '') {
                try {
                    $rows = $backups->listIn($from)->map(fn ($b) => [$b['name'], $b['folder'] ? 'folder' : number_format(($b['bytes'] ?? 0) / 1048576, 1).' MB', $b['path']])->all();
                } catch (Throwable $e) {
                    $this->error("\"{$from->name}\" cannot be read: ".Destinations::reason($e));

                    return self::FAILURE;
                }
                $rows ? $this->table(['Backup', 'Size', 'Path in the storage'], $rows) : $this->warn("No backups found in \"{$from->name}\".");

                return self::SUCCESS;
            }
        }

        if ($name === '') {
            $rows = $backups->available()->map(fn ($b) => [
                $b['name'], $b['bytes'] === null ? '?' : number_format($b['bytes'] / 1048576, 1).' MB', $b['at']->format('Y-m-d H:i'), $b['plan'], $b['where'],
            ])->all();
            $rows ? $this->table(['Backup', 'Size', 'Made', 'Plan', 'Kept in'], $rows) : $this->warn('No backups found. On a new server name the file: --file=/path/to/backup.zip');

            return self::SUCCESS;
        }

        $database = $this->option('database') ?: null;
        $what = "THIS shop's database"
            .($this->option('no-files') ? '' : ', uploaded files')
            .($this->option('code') ? ', modules and themes' : '');

        if (! $database && ! $this->option('force')
            && ! $this->confirm("Replace $what with $name? Everything since then is lost.")) {
            return self::FAILURE;
        }

        $password = $this->option('password') !== null ? (string) $this->option('password') : null;
        $restore = fn (?string $password) => $backups->restore(
            $name, $database, ! $this->option('no-files'), fn (string $message) => $this->line('  '.$message),
            (bool) $this->option('code'), $file, $password, (bool) $this->option('with-env'), $from,
            true, $this->option('base') ? (string) $this->option('base') : null,
        );

        try {
            try {
                $manifest = $restore($password);
            } catch (\RuntimeException $e) {
                // Encrypted, and no password known: asked once, never echoed.
                if ($password !== null || ! str_contains($e->getMessage(), 'give its password') || ! $this->input->isInteractive()) {
                    throw $e;
                }
                $manifest = $restore((string) $this->secret('The backup is encrypted. Its password'));
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Restored $name (made ".($manifest['created_at'] ?? '?').', engine '.($manifest['engine'] ?? '?').').');

        if (($manifest['engine'] ?? null) && $manifest['engine'] !== config('torgash.version')) {
            $this->warn("The backup was made by engine {$manifest['engine']}, this code is ".config('torgash.version').'.');
        }

        if (! empty($manifest['env_written'])) {
            $this->info('The backup\'s .env is in place (the one before is .env.before-restore). Check its database settings fit THIS server, then "php artisan optimize:clear".');
        }

        if (($manifest['key_matches'] ?? null) === false) {
            $this->error('This server\'s APP_KEY is NOT the one the backup was made under. Gift card codes, the mail password, '
                .'second factors and storage passwords cannot be read. Put the old APP_KEY into .env — or type those again.'
                .(! empty($manifest['env_in_backup']) && empty($manifest['env_written']) ? ' The backup carries its .env: add --with-env to put it in place.' : ''));
        } elseif (! empty($manifest['env_in_backup']) && empty($manifest['env_written'])) {
            $this->line('The backup also carries the .env it was made with: add --with-env to put it in place.');
        }

        if (($manifest['cyrillic_ok'] ?? true) === false) {
            $this->error('This database was created with the C locale: Russian search will not work. Create it again as INSTALL.md says and restore once more.');
        }

        if (! empty($manifest['lock_written'])) {
            $this->line('This copy of the code is marked as an installed shop (storage/app/installed).');
        }

        if (! $this->option('code') && in_array('code', $manifest['contents'] ?? [], true)) {
            $this->line('The backup also holds the modules and themes: add --code to put them back.');
        }

        if (! empty($manifest['base_restored'])) {
            $this->line('The backup held only the files changed since '.($manifest['files_since'] ?? '?').': the files of the full backup '.($manifest['base'] ?? '?').' were put back first, these over them.');
        }

        if (($manifest['thumbnails'] ?? true) === false && ! $this->option('no-files') && ! $database) {
            $this->line('The backup left the thumbnails out: the Media module\'s "Rebuild all thumbnails" makes them again.');
        }

        if (! empty($manifest['files_in_bucket'])) {
            $this->line('The uploaded files were in a bucket and are not in the backup: point .env at that bucket.');
        }

        if (! $database) {
            $this->line('Then: "php artisan migrate --force" (when the code is newer than the backup), "php artisan optimize:clear", "php artisan torgash:styles".');
        }

        return self::SUCCESS;
    }
}
