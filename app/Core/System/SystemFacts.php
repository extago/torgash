<?php

namespace App\Core\System;

use App\Core\Files\FileStorage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What the server is, gathered for Admin → System (PLAN §45) — read-only,
 * and nothing here is a secret: versions, limits, sizes and whether the
 * moving parts (queue, scheduler) are moving.
 *
 * It COLLECTS what other screens already know rather than duplicating them:
 * where files live is the storage screen's, the cache is the cache screen's,
 * failed jobs are the tasks screen's — this page links there.
 */
class SystemFacts
{
    /** Extensions the engine needs, and the ones it uses when present. */
    public const REQUIRED_EXTENSIONS = ['pdo_pgsql', 'mbstring', 'intl', 'gd', 'fileinfo', 'openssl', 'curl', 'zip', 'bcmath', 'dom', 'xml', 'iconv'];

    public const OPTIONAL_EXTENSIONS = ['opcache', 'redis', 'exif', 'sodium', 'imagick'];

    /** The scheduler writes this every minute; its age says whether cron runs. */
    public const HEARTBEAT = 'core.schedule.heartbeat';

    /** @return array<string, mixed> */
    public function engine(): array
    {
        return [
            'engine' => (string) config('torgash.version', '0'),
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
            'env' => (string) config('app.env'),
            'debug' => (bool) config('app.debug'),
            'url' => (string) config('app.url'),
            'timezone' => (string) config('app.timezone'),
        ];
    }

    /**
     * The PHP limits that decide what a shop can do: how large an upload, how
     * much memory an import gets. Read from the web request — the CLI often
     * has others, and the page says so.
     *
     * @return array<string, string>
     */
    public function limits(): array
    {
        return [
            'memory_limit' => (string) ini_get('memory_limit'),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'max_input_vars' => (string) ini_get('max_input_vars'),
        ];
    }

    /** @return array{required: array<string, bool>, optional: array<string, bool>} */
    public function extensions(): array
    {
        $loaded = array_map('strtolower', get_loaded_extensions());
        $has = fn (string $ext) => in_array($ext, $loaded, true) || ($ext === 'opcache' && in_array('zend opcache', $loaded, true));

        return [
            'required' => collect(self::REQUIRED_EXTENSIONS)->mapWithKeys(fn ($e) => [$e => $has($e)])->all(),
            'optional' => collect(self::OPTIONAL_EXTENSIONS)->mapWithKeys(fn ($e) => [$e => $has($e)])->all(),
        ];
    }

    /**
     * The database: its version, size, the largest tables — and its LOCALE,
     * the one setting that breaks Russian search with no error anywhere (see
     * CLAUDE.md: under LC_CTYPE=C, lower() leaves Cyrillic alone and pg_trgm
     * finds nothing).
     *
     * @return array<string, mixed>
     */
    public function database(): array
    {
        try {
            $name = DB::connection()->getDatabaseName();
            $row = DB::selectOne(
                'select version() as version, pg_database_size(current_database()) as bytes,
                        datcollate, datctype, datlocprovider::text as provider
                   from pg_database where datname = current_database()'
            );

            $tables = DB::select(
                "select relname as name, pg_total_relation_size(c.oid) as bytes, coalesce(c.reltuples, 0)::bigint as rows
                   from pg_class c join pg_namespace n on n.oid = c.relnamespace
                  where c.relkind = 'r' and n.nspname = 'public'
                  order by pg_total_relation_size(c.oid) desc limit 10"
            );

            $cyrillicOk = (bool) DB::selectOne("select lower('Ж') = 'ж' as ok")->ok;

            return [
                'ok' => true,
                'name' => $name,
                'version' => preg_replace('/^(PostgreSQL [\d.]+).*/', '$1', (string) $row->version),
                'bytes' => (int) $row->bytes,
                'collate' => (string) $row->datcollate,
                'ctype' => (string) $row->datctype,
                'provider' => $row->provider === 'i' ? 'icu' : ($row->provider === 'b' ? 'builtin' : 'libc'),
                'cyrillic_ok' => $cyrillicOk,
                'tables' => array_map(fn ($t) => ['name' => $t->name, 'bytes' => (int) $t->bytes, 'rows' => max(0, (int) $t->rows)], $tables),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Free space where the engine writes — the server's own disk, which is
     * also where the files are while they are a local folder.
     *
     * @return array{path: string, free: ?int, total: ?int, files_local: bool}
     */
    public function disk(): array
    {
        $path = storage_path();

        return [
            'path' => $path,
            'free' => ($free = @disk_free_space($path)) === false ? null : (int) $free,
            'total' => ($total = @disk_total_space($path)) === false ? null : (int) $total,
            'files_local' => ! app(FileStorage::class)->onS3(),
        ];
    }

    /**
     * Is background work moving? Jobs waiting and how long the oldest has
     * waited (a queue nobody works says so by that age), failed jobs, and the
     * scheduler's heartbeat.
     *
     * @return array<string, mixed>
     */
    public function background(): array
    {
        $queue = (string) config('queue.default');
        $waiting = null;
        $oldest = null;

        if ($queue === 'database') {
            try {
                $waiting = DB::table('jobs')->count();
                $first = DB::table('jobs')->min('created_at');
                $oldest = $first ? max(0, time() - (int) $first) : null;
            } catch (Throwable) {
            }
        }

        $failed = null;
        try {
            $failed = DB::table('failed_jobs')->count();
        } catch (Throwable) {
        }

        $beat = Cache::get(self::HEARTBEAT);

        return [
            'queue' => $queue,
            'waiting' => $waiting,
            'oldest_seconds' => $oldest,
            'failed' => $failed,
            'failed_missing' => $failed ? count(app(\App\Core\Tasks\OrphanJobs::class)->missingFailed()) : 0,
            'scheduler_seconds' => $beat ? max(0, time() - (int) $beat) : null,
            'cache' => (string) config('cache.default'),
            'session' => (string) config('session.driver'),
            'mail' => (string) config('mail.default'),
        ];
    }

    /**
     * Things worth fixing, as sentences — the page leads with them.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];
        $engine = $this->engine();
        $database = $this->database();
        $background = $this->background();
        $disk = $this->disk();

        if ($engine['debug'] && $engine['env'] === 'production') {
            $warnings[] = __('Debug mode is on in production: an error shows visitors the code and the settings. Set APP_DEBUG=false.');
        }

        foreach ($this->extensions()['required'] as $ext => $ok) {
            if (! $ok) {
                $warnings[] = __('The PHP extension ":ext" is missing.', ['ext' => $ext]);
            }
        }

        if (($database['ok'] ?? false) && ! $database['cyrillic_ok']) {
            $warnings[] = __('The database was created with the C locale: Cyrillic is not folded and search half-works. Recreate it with an ICU locale — see the installation notes.');
        }

        if ($background['scheduler_seconds'] === null || $background['scheduler_seconds'] > 300) {
            $warnings[] = __('The scheduler is not running: nightly jobs (backups, clean-ups, exchange rates) wait for a cron entry calling "artisan schedule:run" every minute.');
        }

        if (($background['oldest_seconds'] ?? 0) > 600) {
            $warnings[] = __('Jobs have waited more than ten minutes: no queue worker seems to be running ("artisan queue:work").');
        }

        if ($disk['free'] !== null && $disk['total'] && $disk['free'] / $disk['total'] < 0.1) {
            $warnings[] = __('Less than a tenth of the disk is free.');
        }

        return $warnings;
    }
}
