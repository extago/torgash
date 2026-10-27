<?php

namespace App\Core\Settings;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DB-backed settings registry. A setting belongs to a module and may be
 * overridden per storefront (storefront value wins over the global one).
 * Defaults come from module manifests and are never written to the DB.
 */
class SettingsManager
{
    /** @var array<string, array<string, mixed>> module => key => default */
    protected array $defaults = [];

    /** @var array<string, array<string, mixed>> "module|storefront" => key => value */
    protected array $cache = [];

    /**
     * Whether the whole table has been read. It is read at once, not module by
     * module: every page asks five to seven modules for their settings, and
     * `torgash.speed` measured exactly that — the same query, five to seven
     * times a page, for a table of a few hundred short rows.
     */
    protected bool $everything = false;

    public function registerDefaults(string $module, array $defaults): void
    {
        $this->defaults[$module] = $defaults + ($this->defaults[$module] ?? []);
    }

    public function get(string $module, string $key, mixed $default = null, ?int $storefrontId = null): mixed
    {
        if ($storefrontId !== null) {
            $scoped = $this->loaded($module, $storefrontId);
            if (array_key_exists($key, $scoped)) {
                return $scoped[$key];
            }
        }

        $global = $this->loaded($module, null);
        if (array_key_exists($key, $global)) {
            return $global[$key];
        }

        return $this->defaults[$module][$key] ?? $default;
    }

    /** Was a value STORED for the key (this storefront's or the global one) — not merely defaulted? */
    public function has(string $module, string $key, ?int $storefrontId = null): bool
    {
        return ($storefrontId !== null && array_key_exists($key, $this->loaded($module, $storefrontId)))
            || array_key_exists($key, $this->loaded($module, null));
    }

    public function set(string $module, string $key, mixed $value, ?int $storefrontId = null): void
    {
        $before = $this->get($module, $key, null, $storefrontId);

        DB::table('settings')->updateOrInsert(
            ['module' => $module, 'key' => $key, 'storefront_id' => $storefrontId],
            ['value' => json_encode($value, JSON_UNESCAPED_UNICODE)],
        );

        $this->reset();

        $this->announce($module, $key, $before, $value, $storefrontId);
    }

    public function forget(string $module, string $key, ?int $storefrontId = null): void
    {
        $before = $this->get($module, $key, null, $storefrontId);

        DB::table('settings')
            ->where(['module' => $module, 'key' => $key, 'storefront_id' => $storefrontId])
            ->delete();

        $this->reset();

        $this->announce($module, $key, $before, $this->get($module, $key, null, $storefrontId), $storefrontId);
    }

    /**
     * Core saying what it just changed about itself — the same shape as
     * `core.currency.rate_changed`, and announced from HERE for the same
     * reason it is announced from the Currency model: every door that writes a
     * setting (the settings screen, a module's own screen, an installer)
     * announces it without having to remember to.
     *
     * Announced only when the value actually MOVED, and compared loosely on
     * purpose: the settings screen saves every field on the page, a checkbox
     * arrives as the string "1" where the stored one is the number 1, and a
     * hook that reports forty changes nobody made is a hook nobody can listen
     * to. Loose comparison also gets arrays right — two lists with the same
     * pairs in a different order are the same setting.
     */
    protected function announce(string $module, string $key, mixed $before, mixed $after, ?int $storefrontId): void
    {
        if ($before == $after) {
            return;
        }

        hook_action('core.setting.changed', [
            'module' => $module,
            'key' => $key,
            'from' => $before,
            'to' => $after,
            'storefront_id' => $storefrontId,
        ]);
    }

    protected function loaded(string $module, ?int $storefrontId): array
    {
        $cacheKey = $module.'|'.($storefrontId ?? 'g');

        if (! isset($this->cache[$cacheKey]) && ! $this->everything) {
            $this->loadEverything();
        }

        return $this->cache[$cacheKey] ?? [];
    }

    /**
     * Every module and storefront at once. A database that cannot be read yet
     * (an install, a migration in progress) is answered with defaults — and
     * asked again next time, since nothing was really learnt.
     */
    protected function loadEverything(): void
    {
        try {
            $rows = DB::table('settings')->get(['module', 'key', 'storefront_id', 'value']);
        } catch (Throwable) {
            return;
        }

        foreach ($rows as $row) {
            $this->cache[$row->module.'|'.($row->storefront_id ?? 'g')][$row->key] = json_decode($row->value, true);
        }

        $this->everything = true;
    }

    /** A write: the next read sees the table as it now is. */
    protected function reset(): void
    {
        $this->cache = [];
        $this->everything = false;
    }

    /** Forget the values read, keep the registered defaults — before every queue job (PerRequest::flushPerJob). */
    public function flush(): void
    {
        $this->reset();
    }
}
