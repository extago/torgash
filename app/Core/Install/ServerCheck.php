<?php

namespace App\Core\Install;

use App\Core\System\SystemFacts;
use PDO;
use Throwable;

/**
 * Whether this server can run the shop — asked BEFORE anything is written
 * (PLAN §54). The installer refuses to go on past a failed requirement and
 * only mentions a failed recommendation; the console and the web wizard
 * print the same list.
 *
 * The extensions are SystemFacts' list, so Admin → System and the installer
 * never disagree about what the engine needs.
 *
 * The database is checked over a bare PDO connection, not Laravel's: before
 * the install there is no configured connection to speak of, and the answer
 * has to be about the address the owner just typed.
 */
class ServerCheck
{
    public const MIN_PHP = '8.5.0';

    /** CREATE DATABASE … LOCALE_PROVIDER icu needs 15; the engine is built and tested on 18. */
    public const MIN_POSTGRES = 15;

    public const TESTED_POSTGRES = 18;

    /** Where the engine writes. */
    public const WRITABLE = ['storage', 'bootstrap/cache', 'public'];

    /**
     * @return list<array{label: string, ok: bool, required: bool, detail: string}>
     */
    public function server(): array
    {
        $checks = [];

        $checks[] = $this->row(
            __('PHP :version or newer', ['version' => self::MIN_PHP]),
            version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            true,
            'PHP '.PHP_VERSION,
        );

        $loaded = array_map('strtolower', get_loaded_extensions());
        foreach (SystemFacts::REQUIRED_EXTENSIONS as $extension) {
            $checks[] = $this->row(__('PHP extension :name', ['name' => $extension]), in_array($extension, $loaded, true), true);
        }
        foreach (['sodium', 'exif', 'opcache'] as $extension) {
            $present = in_array($extension, $loaded, true) || ($extension === 'opcache' && in_array('zend opcache', $loaded, true));
            $checks[] = $this->row(__('PHP extension :name', ['name' => $extension]), $present, false, $present ? '' : __('recommended'));
        }

        foreach (self::WRITABLE as $path) {
            $absolute = base_path($path);
            $checks[] = $this->row(__('Writable: :path', ['path' => $path]), is_dir($absolute) && is_writable($absolute), true, $absolute);
        }

        $envWritable = is_file(base_path('.env')) ? is_writable(base_path('.env')) : is_writable(base_path());
        $checks[] = $this->row(__('Writable: :path', ['path' => '.env']), $envWritable, true, base_path('.env'));

        // The stylesheet builder, backups and updates start processes.
        $procOpen = function_exists('proc_open') && ! in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
        $checks[] = $this->row(__('proc_open() allowed'), $procOpen, false, $procOpen ? '' : __('needed for backups, updates and rebuilding the stylesheet'));

        return $checks;
    }

    /**
     * The database server at the typed address: reachable, new enough, and —
     * when the database already exists — created with a locale that treats
     * Cyrillic as letters.
     *
     * @param  array{host: string, port: int|string, database: string, username: string, password: ?string}  $db
     * @return list<array{label: string, ok: bool, required: bool, detail: string}>
     */
    public function database(array $db): array
    {
        try {
            $pdo = self::connect($db, 'postgres');
        } catch (Throwable $e) {
            try {
                // Some hosts do not let the user into `postgres`: the shop's
                // own database answers the same questions.
                $pdo = self::connect($db, $db['database']);
            } catch (Throwable) {
                return [$this->row(__('Database server reachable'), false, true, $e->getMessage())];
            }
        }

        $checks = [$this->row(__('Database server reachable'), true, true, $db['host'].':'.$db['port'])];

        $major = (int) $pdo->query('show server_version_num')->fetchColumn() / 10000;
        $checks[] = $this->row(
            __('PostgreSQL :version or newer', ['version' => self::MIN_POSTGRES]),
            $major >= self::MIN_POSTGRES,
            true,
            'PostgreSQL '.(int) $major.($major >= self::MIN_POSTGRES && $major < self::TESTED_POSTGRES ? ' — '.__('works; the engine is tested on :version', ['version' => self::TESTED_POSTGRES]) : ''),
        );

        $row = $pdo->prepare('select datctype, datlocprovider::text as provider from pg_database where datname = ?');
        $row->execute([$db['database']]);
        $existing = $row->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $c = $existing['datctype'] === 'C' && $existing['provider'] === 'c';
            $checks[] = $this->row(
                __('Database locale folds Cyrillic'),
                ! $c,
                true,
                $c ? __('the database ":name" was created with the C locale: Russian search would half-work. Drop it, or let the installer create it.', ['name' => $db['database']]) : $existing['datctype'],
            );
        } else {
            $can = (bool) $pdo->query('select rolcreatedb or rolsuper from pg_roles where rolname = current_user')->fetchColumn();
            $checks[] = $this->row(
                __('Can create the database'),
                $can,
                true,
                $can ? __('it will be created') : __('the user may not create databases — create ":name" yourself:', ['name' => $db['database']]).' '.Installer::createDatabaseSql($db['database']),
            );
        }

        return $checks;
    }

    /** @param  list<array{ok: bool, required: bool}>  $checks */
    public static function passes(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['required'] && ! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @param  array{host: string, port: int|string, username: string, password: ?string}  $db */
    public static function connect(array $db, string $database): PDO
    {
        return new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $db['host'], (int) $db['port'], $database),
            $db['username'],
            $db['password'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
        );
    }

    /** @return array{label: string, ok: bool, required: bool, detail: string} */
    protected function row(string $label, bool $ok, bool $required, string $detail = ''): array
    {
        return ['label' => $label, 'ok' => $ok, 'required' => $required, 'detail' => $detail];
    }
}
