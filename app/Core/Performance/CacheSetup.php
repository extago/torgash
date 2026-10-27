<?php

namespace App\Core\Performance;

use App\Core\Support\EnvFile;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Where the shop keeps its cache and its sessions — the one class the "Cache
 * and sessions" screen, the guard and the speed report all ask.
 *
 * Three answers for each, in words a shop owner can choose between: a FOLDER
 * on this server (one server, nothing to run), the DATABASE (several servers
 * sharing one), REDIS (fastest; a server of its own to run). The choice is
 * written to .env, not the settings table: the cache is needed before the
 * settings can be read, and the Redis password is a secret. Redis always
 * means Redis WITH the database behind it (`redis-fallback`,
 * `FallbackStore`), so a Redis that stops slows the shop rather than stopping
 * it — which is why nothing here refuses to save a Redis that is up today and
 * the guard, not the save, is what watches it tomorrow.
 */
class CacheSetup
{
    public const CHOICES = ['file', 'database', 'redis'];

    /** Cache store / session driver written to .env for each choice. */
    protected const CACHE_STORE = ['file' => 'file', 'database' => 'database', 'redis' => 'redis-fallback'];

    protected const SESSION_DRIVER = ['file' => 'file', 'database' => 'database', 'redis' => 'redis-fallback'];

    /** The stores a Redis outage is recorded under (FallbackState). */
    public const REDIS_STORES = ['redis', 'redis-sessions'];

    /**
     * What is in force now: `cache` / `sessions` as one of CHOICES, or the
     * raw driver name when .env says something this screen did not write.
     *
     * @return array{cache: string, sessions: string}
     */
    public function current(): array
    {
        $cache = (string) config('cache.default');
        $sessions = (string) config('session.driver');

        return [
            'cache' => array_search($cache, self::CACHE_STORE, true) ?: ($cache === 'redis' ? 'redis' : $cache),
            'sessions' => array_search($sessions, self::SESSION_DRIVER, true) ?: $sessions,
        ];
    }

    public function usesRedis(): bool
    {
        return in_array('redis', $this->current(), true);
    }

    /**
     * The Redis connection as configured now (the password only as "set").
     *
     * @return array{host: string, port: int, has_password: bool, extension: bool, client: string}
     */
    public function redis(): array
    {
        $config = config('database.redis.cache', []);

        return [
            'host' => (string) ($config['host'] ?? '127.0.0.1'),
            'port' => (int) ($config['port'] ?? 6379),
            'has_password' => filled($config['password'] ?? null),
            'extension' => extension_loaded('redis'),
            'client' => (string) config('database.redis.client', 'phpredis'),
        ];
    }

    /** Whether the machine can talk to Redis at all (phpredis or predis). */
    public function redisClientAvailable(): bool
    {
        return extension_loaded('redis') || class_exists(\Predis\Client::class);
    }

    /**
     * The last outage of the Redis stores, the most recent first; null when
     * Redis has never been away.
     *
     * @return array{store: string, down_at: int, tried_at: int, error: string, up_at: ?int}|null
     */
    public function lastOutage(): ?array
    {
        $state = app(FallbackState::class);
        $last = null;

        foreach (self::REDIS_STORES as $store) {
            $row = $state->last($store);
            if ($row && (! $last || $row['down_at'] > $last['down_at'])) {
                $last = ['store' => $store, ...$row];
            }
        }

        return $last;
    }

    /**
     * Writes a key, reads it back and deletes it, on the store a choice would
     * use — with the Redis connection given (typed on the form, not yet saved)
     * or the configured one.
     *
     * @param  array{host?: string, port?: int|string, password?: ?string}|null  $redis
     * @return array{ok: bool, ms: int, error: ?string, version?: string, memory?: string}
     */
    public function probe(string $choice, ?array $redis = null): array
    {
        $started = hrtime(true);

        try {
            $extra = match ($choice) {
                'redis' => $this->probeRedis($redis),
                'file', 'database' => $this->probeStore($choice),
                default => throw new \InvalidArgumentException("Unknown choice: {$choice}"),
            };

            return ['ok' => true, 'ms' => $this->ms($started), 'error' => null, ...$extra];
        } catch (Throwable $e) {
            return ['ok' => false, 'ms' => $this->ms($started), 'error' => Str::limit($e->getMessage(), 300)];
        }
    }

    /**
     * The .env lines for a choice. The password is written only when one was
     * typed — a blank box keeps the stored one, the rule for every credential
     * form in this engine — and `clear_password` removes it.
     *
     * @param  array{host?: string, port?: int|string, password?: ?string, clear_password?: bool}  $redis
     * @return array<string, string>
     */
    public function envFor(string $cache, string $sessions, array $redis = []): array
    {
        $values = [
            'CACHE_STORE' => self::CACHE_STORE[$cache],
            'SESSION_DRIVER' => self::SESSION_DRIVER[$sessions],
        ];

        if ($cache === 'redis' || $sessions === 'redis') {
            $values['REDIS_CLIENT'] = extension_loaded('redis') ? 'phpredis' : 'predis';
            $values['REDIS_HOST'] = trim((string) ($redis['host'] ?? '')) ?: '127.0.0.1';
            $values['REDIS_PORT'] = (string) ((int) ($redis['port'] ?? 0) ?: 6379);

            if (! empty($redis['clear_password'])) {
                $values['REDIS_PASSWORD'] = 'null';
            } elseif (filled($redis['password'] ?? null)) {
                $values['REDIS_PASSWORD'] = (string) $redis['password'];
            }
        }

        return $values;
    }

    /**
     * Writes the choice. When the configuration is cached, the cache is
     * rebuilt, or the file would change and the shop would not.
     *
     * @param  array<string, string>  $values
     */
    public function save(array $values): void
    {
        app(EnvFile::class)->set($values);

        if (app()->configurationIsCached()) {
            Artisan::call('config:cache');
        }
    }

    /**
     * How many entries the cache in force holds — a number that says "it is
     * being used", nothing more; null when it cannot be counted cheaply.
     */
    public function entries(): ?int
    {
        try {
            return match ($this->current()['cache']) {
                'database' => \Illuminate\Support\Facades\DB::table((string) config('cache.stores.database.table', 'cache'))->count(),
                'redis' => (int) app('redis')->connection((string) config('cache.stores.redis.connection', 'cache'))->command('dbsize'),
                'file' => iterator_count(new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator((string) config('cache.stores.file.path'), \FilesystemIterator::SKIP_DOTS),
                )),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /** Clears the data cache of the store in force (sessions are elsewhere). */
    public function flush(): bool
    {
        return (bool) Cache::store()->flush();
    }

    /** @return array<string, string> */
    protected function probeStore(string $choice): array
    {
        $store = Cache::store(self::CACHE_STORE[$choice]);
        $key = 'torgash-probe-'.Str::random(8);

        $store->put($key, 'ok', 30);
        $back = $store->get($key);
        $store->forget($key);

        if ($back !== 'ok') {
            throw new \RuntimeException(__('The value written could not be read back.'));
        }

        return [];
    }

    /**
     * Talks to Redis directly through a connection of its own, so a typed
     * host is tried without touching the configured one — and so that a
     * failure is a failure, not the fallback quietly answering instead.
     *
     * @return array<string, string>
     */
    protected function probeRedis(?array $typed): array
    {
        if (! $this->redisClientAvailable()) {
            throw new \RuntimeException(__('PHP has no Redis client: the phpredis extension is not loaded.'));
        }

        $base = config('database.redis.cache', []);
        $config = [
            ...$base,
            'url' => null,
            'host' => trim((string) ($typed['host'] ?? '')) ?: ($base['host'] ?? '127.0.0.1'),
            'port' => (int) ($typed['port'] ?? 0) ?: (int) ($base['port'] ?? 6379),
            'password' => filled($typed['password'] ?? null) ? $typed['password'] : ($base['password'] ?? null),
            'max_retries' => 0,
            'timeout' => 2,
            'read_timeout' => 2,
        ];

        $manager = new RedisManager(app(), extension_loaded('redis') ? 'phpredis' : 'predis', [
            'options' => config('database.redis.options', []),
            'probe' => $config,
        ]);

        $connection = $manager->connection('probe');
        $key = 'torgash-probe-'.Str::random(8);
        // SETEX, not SET … EX: phpredis's set() takes the expiry as an options
        // array and refuses a fourth argument ("expects at most 3 arguments"),
        // so the check reported a working Redis as not answering. SETEX means
        // the same to both clients.
        $connection->command('setex', [$key, 30, 'ok']);
        $back = $connection->command('get', [$key]);
        $connection->command('del', [$key]);

        if ($back !== 'ok') {
            throw new \RuntimeException(__('The value written could not be read back.'));
        }

        $info = (array) $connection->command('info');
        // purge(), not disconnect(): the manager has no disconnect() of its
        // own, so __call handed it to the DEFAULT connection — which this
        // manager does not have, and a Redis that had just answered was
        // reported as "connection [default] not configured" (live shop,
        // 2026-10-23).
        $manager->purge('probe');

        return array_filter([
            'version' => self::serverName($info),
            'memory' => (string) self::infoField($info, 'used_memory_human'),
        ]);
    }

    /**
     * Which server answered, as a person would name it: "Redis 7.4.2" or
     * "Valkey 8.1.1". Valkey speaks the same protocol and goes on reporting
     * `redis_version:7.2.4` (the version it forked from) for clients that
     * check it; its own name and version are in `server_name` and
     * `valkey_version`.
     *
     * @param  array<string, mixed>  $info  INFO as either client returns it
     */
    public static function serverName(array $info): string
    {
        $valkey = (string) self::infoField($info, 'valkey_version');

        if ($valkey !== '' || strtolower((string) self::infoField($info, 'server_name')) === 'valkey') {
            return trim('Valkey '.$valkey);
        }

        $redis = (string) self::infoField($info, 'redis_version');

        return $redis === '' ? '' : 'Redis '.$redis;
    }

    /** One INFO field: flat from phpredis, under its section (`Server`, `Memory`) from predis. */
    protected static function infoField(array $info, string $field): mixed
    {
        if (array_key_exists($field, $info)) {
            return $info[$field];
        }

        foreach ($info as $section) {
            if (is_array($section) && array_key_exists($field, $section)) {
                return $section[$field];
            }
        }

        return null;
    }

    protected function ms(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
