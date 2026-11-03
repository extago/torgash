<?php

namespace App\Core\Install;

use Illuminate\Console\Command;
use Throwable;

/**
 * `torgash:install` — a shop from an unpacked distribution, in one command
 * (PLAN §54). Every answer is a flag, so a script or a provisioning tool can
 * run it unattended; whatever is left out is asked when somebody is there to
 * answer, and refused when nobody is.
 *
 * `--restore=<backup>` raises a shop from a backup instead (PLAN §67.8, 6):
 * the old APP_KEY (`--app-key`, or `--with-env` from an encrypted backup, or
 * `--new-key` knowing what it costs), the database, nothing about the shop —
 * the backup knows its name, language, modules and owner.
 *
 * The server is checked first and nothing is written while a requirement
 * fails. Running it again after a failure finishes the install (Installer
 * says why that is safe); running it over a finished one is refused.
 */
class InstallCommand extends Command
{
    protected $signature = 'torgash:install
        {--url= : The shop\'s address, e.g. https://shop.example.com}
        {--name= : The shop\'s name}
        {--locale= : The storefront\'s language: en or ru}
        {--currency= : The currency the shop keeps its books in, e.g. UAH}
        {--country= : The shop\'s country, two letters (UA, PL…): its carriers and payment gateways come with the module set; left out, read off the currency (RUB → RU)}
        {--timezone= : The shop\'s time zone, e.g. Europe/Moscow; left out, the country\'s}
        {--db-host=127.0.0.1} {--db-port=5432}
        {--db-database=torgash} {--db-username=} {--db-password=}
        {--modules=store : A set (minimal, store, all) or module ids separated by commas}
        {--owner= : The owner\'s email}
        {--owner-name= : The owner\'s name}
        {--owner-password= : The owner\'s password; left out, one is made up and shown once}
        {--no-styles : Keep the shipped stylesheet instead of building one}
        {--demo : Fill the shop with the demo data — products, sales, pages, a blog; System removes it in one go}
        {--check : Only check the server and the database, write nothing}
        {--restore= : Raise the shop from this backup (a file, or the folder of one made without an archive) instead of installing a new one}
        {--password= : The password of an encrypted backup}
        {--app-key= : The old shop\'s APP_KEY (from its .env): what it encrypted opens again}
        {--with-env : Take the .env an encrypted backup carries (its key, mail, storage) — this server\'s database and address are written over it}
        {--new-key : Start with a new key: gift card codes, the mail password, storage passwords and second factors of the old shop are lost}
        {--pg-bin= : With --restore: the folder of pg_restore / psql when they are not on the PATH}
        {--no-files : With --restore: leave the uploaded files out}
        {--code : With --restore: put the backup\'s modules and themes back too (paid modules, the shop\'s own theme)}';

    protected $description = 'Install Torgash: check the server, write .env, create the database, install the modules and the owner';

    public function handle(ServerCheck $check): int
    {
        if (Installer::installed()) {
            $this->error('Torgash is already installed here ('.storage_path(Installer::LOCK).').');

            return self::FAILURE;
        }

        $this->line('<options=bold>The server</>');
        $server = $check->server();
        $this->report($server);

        $db = [
            'host' => (string) $this->option('db-host'),
            'port' => (int) $this->option('db-port'),
            'database' => (string) $this->option('db-database'),
            'username' => (string) ($this->option('db-username') ?? $this->ask('Database user', 'postgres')),
            'password' => $this->option('db-password') ?? ($this->input->isInteractive() ? (string) $this->secret('Database password (empty for none)') : ''),
        ];

        $this->newLine();
        $this->line('<options=bold>The database</>');
        $database = $check->database($db);
        $this->report($database);

        if (! ServerCheck::passes($server) || ! ServerCheck::passes($database)) {
            $this->newLine();
            $this->error('Fix what is marked ✗ above and run the installer again. Nothing was written.');

            return self::FAILURE;
        }

        if ($this->option('check')) {
            $this->newLine();
            $this->info('The server is ready for Torgash.');

            return self::SUCCESS;
        }

        if ($this->option('restore')) {
            return $this->restore($db);
        }

        $answers = [
            'url' => $this->need('url', 'The shop\'s address (https://…)'),
            'name' => $this->need('name', 'The shop\'s name', 'My shop'),
            'locale' => $this->need('locale', 'Storefront language (en, ru)', 'ru'),
            'currency' => strtoupper($this->need('currency', 'Books currency (UAH, USD, EUR…)', 'UAH')),
            'country' => strtoupper((string) ($this->option('country') ?? ($this->input->isInteractive() ? $this->ask('The shop\'s country, two letters (empty for none)', '') : ''))),
            'timezone' => (string) ($this->option('timezone') ?? ''),
            'db' => $db,
            'modules' => $this->modules(),
            'owner' => [
                'email' => $this->need('owner', 'The owner\'s email'),
                'name' => $this->option('owner-name'),
                'password' => $this->option('owner-password'),
            ],
            'styles' => ! $this->option('no-styles'),
            'demo' => (bool) $this->option('demo'),
        ];

        $this->newLine();

        try {
            $result = (new Installer)->run($answers, fn (string $line) => $this->line(str_starts_with($line, '  ') ? '<fg=gray>'.$line.'</>' : '→ '.$line));
        } catch (Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());
            $this->line('Fix it and run the same command again: the installer goes on from where it stopped.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Torgash '.config('torgash.version').' is installed: '.count($result['modules']).' modules.');
        $this->line('Admin: '.rtrim($answers['url'], '/').'/admin — '.$answers['owner']['email']);
        if ($result['owner_password'] !== null) {
            $this->line('Password: '.$result['owner_password']);
            $this->warn('Shown once. Write it down, sign in and change it in your profile.');
        }

        $this->newLine();
        $this->line('<options=bold>One line for cron</> (letters, backups, nightly jobs — the queue too):');
        $this->line('  * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1');
        $this->line('<fg=gray>A queue worker of its own (systemd, supervisor) is faster for a busy shop — then set QUEUE_WORKER=external. See INSTALL.md.</>');

        return self::SUCCESS;
    }

    protected function restore(array $db): int
    {
        $file = (string) $this->option('restore');
        $password = $this->option('password');
        $answers = [
            'url' => (string) ($this->option('url') ?? ''),
            'db' => $db,
            'styles' => ! $this->option('no-styles'),
            'restore' => [
                'file' => $file,
                'password' => $password,
                'app_key' => $this->option('app-key'),
                'with_env' => (bool) $this->option('with-env'),
                'new_key' => (bool) $this->option('new-key'),
                'files' => ! $this->option('no-files'),
                'code' => (bool) $this->option('code'),
                'pg_bin' => (string) $this->option('pg-bin'),
            ],
        ];

        $this->newLine();

        try {
            // An encrypted backup and no password: asked, when somebody is there.
            if (($password === null || $password === '') && $this->input->isInteractive() && $this->encrypted($file)) {
                $answers['restore']['password'] = (string) $this->secret('The backup is encrypted. Its password');
            }

            $result = (new Installer)->run($answers, fn (string $line) => $this->line(str_starts_with($line, '  ') ? '<fg=gray>'.$line.'</>' : '→ '.$line));
        } catch (Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());
            $this->line(Installer::pending() ? 'Fix it and run the same command again: the restore goes on from where it stopped.' : 'Nothing was written.');

            return self::FAILURE;
        }

        $answers = $result['answers'];
        $done = $answers['restore'];
        $this->newLine();
        $this->info('The shop is back from the backup of '.($done['manifest']['created_at'] ?? '?').' (Torgash '.($done['manifest']['engine'] ?? '?').'): '.rtrim($answers['url'], '/').'/admin — sign in with the account you had.');
        if ($done['new_key']) {
            $this->warn('A NEW key: gift card codes, the mail password, storage passwords and second factors of the old shop do not open — type them again.');
        }
        if ($missing = ($result['notes']['missing_modules'] ?? [])) {
            $this->warn('Not in this code: '.implode(', ', $missing).'. Put them into modules/ (or restore with --code).');
        }
        $this->line('<options=bold>One line for cron</>:  * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1');

        return self::SUCCESS;
    }

    protected function encrypted(string $file): bool
    {
        if (! is_file($file)) {
            return false;
        }

        if ((string) file_get_contents($file, false, null, 0, 8) === 'Salted__') {
            return true;
        }

        $zip = new \ZipArchive;
        if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            return (($zip->statIndex(0, \ZipArchive::FL_ENC_RAW)['encryption_method'] ?? 0) !== \ZipArchive::EM_NONE);
        } finally {
            $zip->close();
        }
    }

    /** @param  list<array{label: string, ok: bool, required: bool, detail: string}>  $checks */
    protected function report(array $checks): void
    {
        foreach ($checks as $row) {
            $mark = $row['ok'] ? '<fg=green>✓</>' : ($row['required'] ? '<fg=red>✗</>' : '<fg=yellow>!</>');
            $this->line('  '.$mark.' '.$row['label'].($row['detail'] !== '' ? ' <fg=gray>— '.$row['detail'].'</>' : ''));
        }
    }

    protected function need(string $option, string $question, ?string $default = null): string
    {
        $value = $this->option($option);
        if ($value !== null && $value !== '') {
            return (string) $value;
        }

        if (! $this->input->isInteractive()) {
            if ($default !== null) {
                return $default;
            }
            throw new \RuntimeException('--'.$option.' is required when nobody can be asked.');
        }

        return (string) $this->ask($question, $default);
    }

    /** @return string|list<string> */
    protected function modules(): string|array
    {
        $value = trim((string) $this->option('modules'));

        return str_contains($value, '.') ? array_values(array_filter(array_map('trim', explode(',', $value)))) : $value;
    }
}
