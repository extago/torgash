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
        {--check : Only check the server and the database, write nothing}';

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
