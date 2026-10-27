<?php

namespace App\Core\Performance;

use Illuminate\Cache\FailoverStore;
use RuntimeException;
use Throwable;

/**
 * A cache that keeps working when Redis stops: the stores are tried in order,
 * and one that failed is SKIPPED for a while instead of being asked again.
 *
 * Laravel's own failover store asks the first store on every single call, so a
 * Redis that is down costs a connection attempt per cache read — dozens per
 * page, each one a timeout when the server is on another machine. Here the
 * first failure writes a mark (`FallbackState`, a file on the server's own
 * disk, since the cache is exactly what cannot be trusted to hold it) and the
 * store is left alone until the mark is `RETRY_AFTER` seconds old; then one
 * request tries again. The same mark is what the "Cache and sessions" screen
 * and the guard read to say "Redis stopped answering at 14:02".
 *
 * Registered as the `torgash-fallback` cache driver; a store's config is
 * `['driver' => 'torgash-fallback', 'stores' => ['redis', 'database']]`.
 */
class FallbackStore extends FailoverStore
{
    public const RETRY_AFTER = 30;

    protected function attemptOnAllStores(string $method, array $arguments)
    {
        $state = app(FallbackState::class);
        $last = null;

        foreach ($this->stores as $i => $store) {
            $isLast = $i === array_key_last($this->stores);

            // A store marked down is skipped while the mark is fresh — unless
            // it is the only one left, when trying is better than refusing.
            if (! $isLast && $state->isDown($store, self::RETRY_AFTER)) {
                continue;
            }

            try {
                $result = $this->store($store)->{$method}(...$arguments);
                $state->markUp($store);

                return $result;
            } catch (Throwable $e) {
                $last = $e;
                $state->markDown($store, $e);
            }
        }

        throw $last ?? new RuntimeException('All fallback cache stores failed.');
    }

    /**
     * Clearing clears EVERY store that answers: what the database cache held
     * while Redis was away is served again the next time it goes away, and
     * "Clear the cache" must not leave last month's answers waiting there.
     */
    public function flush()
    {
        $done = false;
        $last = null;

        foreach ($this->stores as $store) {
            try {
                $done = $this->store($store)->flush() || $done;
            } catch (Throwable $e) {
                $last = $e;
            }
        }

        if (! $done && $last) {
            throw $last;
        }

        return $done;
    }
}
