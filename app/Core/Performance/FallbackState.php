<?php

namespace App\Core\Performance;

use Throwable;

/**
 * Which cache store stopped answering, when, and whether it came back — one
 * small JSON file per store under storage/framework/cache, on the server's own
 * disk whatever the shop's file storage is, because the thing being recorded
 * is that the shared store cannot be reached.
 *
 * `{down_at, tried_at, error, up_at}`: `down_at` is the FIRST failure of an
 * outage (a retry that fails again moves only `tried_at`), `up_at` is set when
 * a call succeeds again and is what turns "down" into "was down". Read once per
 * request and memoised — a cache read must not cost a file read.
 */
class FallbackState
{
    /** @var array<string, array<string, mixed>|null> */
    protected array $memo = [];

    public function isDown(string $store, int $retryAfter): bool
    {
        $row = $this->read($store);

        return $row !== null
            && empty($row['up_at'])
            && time() - (int) ($row['tried_at'] ?? 0) < $retryAfter;
    }

    public function markDown(string $store, Throwable $e): void
    {
        $row = $this->read($store);
        $now = time();

        $this->write($store, [
            // A new outage begins when there was none, or the last one ended.
            'down_at' => ($row === null || ! empty($row['up_at'])) ? $now : (int) $row['down_at'],
            'tried_at' => $now,
            'error' => mb_substr($e->getMessage(), 0, 300),
            'up_at' => null,
        ]);
    }

    public function markUp(string $store): void
    {
        $row = $this->read($store);

        if ($row !== null && empty($row['up_at'])) {
            $this->write($store, [...$row, 'up_at' => time()]);
        }
    }

    /**
     * The last outage of a store, or null when it never had one.
     *
     * @return array{down_at: int, tried_at: int, error: string, up_at: ?int}|null
     */
    public function last(string $store): ?array
    {
        return $this->read($store);
    }

    public function forget(string $store): void
    {
        @unlink($this->path($store));
        $this->memo[$store] = null;
    }

    protected function read(string $store): ?array
    {
        if (! array_key_exists($store, $this->memo)) {
            $raw = @file_get_contents($this->path($store));
            $row = $raw === false ? null : json_decode($raw, true);
            $this->memo[$store] = is_array($row) ? $row : null;
        }

        return $this->memo[$store];
    }

    protected function write(string $store, array $row): void
    {
        $this->memo[$store] = $row;
        $path = $this->path($store);
        @mkdir(dirname($path), 0775, true);
        @file_put_contents($path, json_encode($row), LOCK_EX);
    }

    protected function path(string $store): string
    {
        return storage_path('framework/cache/fallback-'.preg_replace('/[^a-z0-9_-]/i', '', $store).'.json');
    }
}
