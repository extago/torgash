<?php

namespace App\Core\PageCache;

use Illuminate\Support\Arr;

use function Illuminate\Support\enum_value;

/**
 * The session, telling the copy being drawn what the page reads and writes
 * (PLAN §61). Silent when nothing is being drawn — which is every request
 * but the one in a few that takes a copy, and every request at all while
 * the page cache is off.
 *
 * The overridden methods are the ones the rest are built on: `has`,
 * `exists`, `token`, `previousUrl` and `getOldInput` read through get(),
 * `push`, `increment`, `flash` and `remember` write through put().
 */
trait WatchesSession
{
    protected ?Capture $watcher = null;

    public function watch(?Capture $capture): void
    {
        $this->watcher = $capture;
    }

    public function get($key, $default = null)
    {
        if ($this->watcher) {
            $key = (string) enum_value($key);
            $this->watcher->sessionRead($key, Arr::has($this->attributes, $key), Arr::get($this->attributes, $key));
        }

        return parent::get($key, $default);
    }

    public function pull($key, $default = null)
    {
        if ($this->watcher) {
            $key = (string) enum_value($key);
            $this->watcher->sessionRead($key, Arr::has($this->attributes, $key), Arr::get($this->attributes, $key));
            $this->watcher->sessionWrite($key);
        }

        return parent::pull($key, $default);
    }

    public function all()
    {
        $this->watcher?->sessionReadAll();

        return parent::all();
    }

    public function only(array $keys)
    {
        foreach ($keys as $key) {
            $this->watcher?->sessionRead((string) $key, Arr::has($this->attributes, (string) $key), Arr::get($this->attributes, (string) $key));
        }

        return parent::only($keys);
    }

    public function except(array $keys)
    {
        $this->watcher?->sessionReadAll();

        return parent::except($keys);
    }

    public function put($key, $value = null)
    {
        if ($this->watcher) {
            foreach (is_array($key) ? array_keys($key) : [enum_value($key)] as $name) {
                $this->watcher->sessionWrite((string) $name);
            }
        }

        parent::put($key, $value);
    }

    public function remove($key)
    {
        $this->watcher?->sessionWrite((string) enum_value($key));

        return parent::remove($key);
    }

    public function forget($keys)
    {
        foreach ((array) $keys as $key) {
            $this->watcher?->sessionWrite((string) enum_value($key));
        }

        parent::forget($keys);
    }

    public function flush()
    {
        $this->watcher?->sessionWrite('everything');

        parent::flush();
    }
}
