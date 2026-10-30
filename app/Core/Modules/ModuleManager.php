<?php

namespace App\Core\Modules;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Throwable;

/**
 * Discovers, installs and boots modules.
 *
 * A module is a self-contained directory under modules/ with a manifest.json.
 * Installing runs its migrations and registers its permissions; uninstalling
 * rolls everything back and removes its settings — no traces left behind.
 * Module code is never copied anywhere and core files are never touched.
 */
class ModuleManager
{
    /** @var array<string, ModuleManifest>|null */
    protected ?array $discovered = null;

    /** @var array<string, bool>|null module_id => enabled */
    protected ?array $statuses = null;

    /** @var array<string, array{0: string, 1: string}>|null  id => [why, what]: enabled in the table, held back here */
    protected ?array $blocked = null;

    /** @var array<string, string> folder => why it could not be read (a manifest that is not one, a provider class that is not there) */
    protected array $broken = [];

    protected static ?ClassLoader $loader = null;

    public function __construct(protected Application $app) {}

    /** All modules present on disk, keyed by id. */
    public function all(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $this->discovered = [];
        $this->broken = [];
        $base = config('torgash.modules_path');

        // One folder that cannot be read must not take the shop down with it:
        // a half-uploaded module, a typo in a third party's manifest. It is
        // left out — not registered, not listed — and said on the Modules
        // screen (broken()); a module that depends on it is held back
        // (blocked()), because its code calls classes that are not loaded.
        foreach (glob($base.'/*/manifest.json') ?: [] as $manifestPath) {
            try {
                $manifest = ModuleManifest::fromFile($manifestPath);
            } catch (Throwable $e) {
                $this->markBroken(basename(dirname($manifestPath)), $e->getMessage());

                continue;
            }

            $this->discovered[$manifest->id] = $manifest;
        }

        return $this->discovered;
    }

    /** @return array<string, string> folder => why it could not be used */
    public function broken(): array
    {
        $this->all();

        return $this->broken;
    }

    protected function markBroken(string $folder, string $why): void
    {
        $this->broken[$folder] = $why;

        // Once a day per reason, not once per request. A file, not the cache:
        // this is asked while the application boots, before the cache is there.
        try {
            $mark = storage_path('framework/cache/module-broken-'.md5($folder.$why));

            if (! is_file($mark) || filemtime($mark) < time() - 86400) {
                @touch($mark);
                \Illuminate\Support\Facades\Log::error("Module folder [{$folder}] is left out: {$why}");
            }
        } catch (Throwable) {
            // No log yet (an install, a broken .env): the Modules screen still says it.
        }
    }

    public function find(string $id): ?ModuleManifest
    {
        return $this->all()[$id] ?? null;
    }

    /** module_id => enabled, from DB. Empty when the table does not exist yet. */
    public function statuses(): array
    {
        if ($this->statuses !== null) {
            return $this->statuses;
        }

        try {
            $this->statuses = DB::table('modules')->pluck('enabled', 'module_id')->all();
        } catch (Throwable) {
            $this->statuses = [];
        }

        return $this->statuses;
    }

    public function isInstalled(string $id): bool
    {
        return array_key_exists($id, $this->statuses());
    }

    /**
     * Switched on AND able to run. A module whose `requires.core` this core
     * does not satisfy (the core was rolled back, or the module put on disk
     * by hand) is held back — not registered, its views and routes absent —
     * and so is everything that depends on it, because their code calls
     * classes that are not loaded. One module that does not fit must not take
     * the shop down with it (PLAN §45.5).
     */
    public function isEnabled(string $id): bool
    {
        return (bool) ($this->statuses()[$id] ?? false) && ! isset($this->blocked()[$id]);
    }

    /**
     * The modules switched on in the table but held back here, with why:
     * `['core', '>=0.3']` or `['module', 'vendor.other']`.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function blocked(): array
    {
        if ($this->blocked !== null) {
            return $this->blocked;
        }

        $statuses = $this->statuses();
        $blocked = [];

        // Switched on in the table, but its folder is gone or unreadable:
        // nothing of it can run, and isEnabled() must say so — a template
        // asking "is the cart on?" would otherwise reach for missing views.
        foreach ($statuses as $id => $on) {
            if ($on && ! isset($this->all()[$id])) {
                $blocked[$id] = ['missing', (string) $id];
            }
        }

        do {
            $changed = false;
            foreach ($this->all() as $id => $manifest) {
                if (empty($statuses[$id]) || isset($blocked[$id])) {
                    continue;
                }
                if (! $manifest->fitsCore()) {
                    $blocked[$id] = ['core', (string) $manifest->coreConstraint()];
                    $changed = true;

                    continue;
                }
                // A manifest naming a class its own files do not have: held
                // back like an unreadable folder, not a fatal error on every page.
                $this->registerAutoload($manifest);
                if (! class_exists($manifest->provider)) {
                    $blocked[$id] = ['provider', $manifest->provider];
                    $changed = true;

                    continue;
                }
                foreach ($manifest->requires['modules'] ?? [] as $dependency) {
                    // Held back, or not there to be loaded at all (its folder gone or unreadable).
                    if (isset($blocked[$dependency]) || ! isset($this->all()[$dependency])) {
                        $blocked[$id] = ['module', $dependency];
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);

        return $this->blocked = $blocked;
    }

    /** Refuses a module this core cannot run, saying which core it wants. */
    public function assertFitsCore(ModuleManifest $manifest): void
    {
        if (! $manifest->fitsCore()) {
            throw new RuntimeException(__(':module needs Torgash :wanted; this is :core.', [
                'module' => $manifest->name, 'wanted' => (string) $manifest->coreConstraint(), 'core' => config('torgash.version'),
            ]));
        }
    }

    /** @return array<string, ModuleManifest> installed AND enabled modules */
    public function enabled(): array
    {
        return array_filter($this->all(), fn ($m) => $this->isEnabled($m->id));
    }

    /** Register autoloading and service providers for all enabled modules. */
    public function registerEnabled(): void
    {
        foreach ($this->enabled() as $manifest) {
            $this->registerAutoload($manifest);
            $this->app->register($manifest->provider);
        }
    }

    public function install(string $id): void
    {
        $manifest = $this->find($id) ?? throw new RuntimeException("Module [{$id}] not found on disk.");

        if ($this->isInstalled($id)) {
            throw new RuntimeException("Module [{$id}] is already installed.");
        }

        $this->assertFitsCore($manifest);

        $this->assertDependenciesEnabled($id);

        // Its classes before its migrations and its lifecycle, which may use
        // them: a module not yet installed was never registered at boot (found
        // by the first install into an empty database — every earlier one ran
        // in a process that had booted with the modules already there).
        $this->registerAutoload($manifest);

        if ($path = $manifest->migrationsPath()) {
            $this->assertMigrationNamesFree($id, $path);
            $this->migrate($path);
        }

        foreach ($manifest->permissionNames() as $permission) {
            Permission::findOrCreate($permission);
        }

        DB::table('modules')->insert([
            'module_id' => $id,
            'version' => $manifest->version,
            'enabled' => true,
            'installed_at' => now(),
        ]);

        $this->statuses = $this->blocked = null;

        // Last, so a module setting itself up finds its own tables, permissions
        // and settings already there. Its settings' DEFAULTS too: they are
        // registered when an enabled module boots, and a module installed in
        // this process never booted — the installer's run read every one of
        // them as null (torgash.locations' packs folder became the project
        // root, 2026-10-27).
        app(\App\Core\Settings\SettingsManager::class)->registerDefaults($manifest->id, $manifest->settingDefaults());
        $this->lifecycle($manifest)?->install();

        $this->announce($id, 'installed');
    }

    /**
     * A newer version of a module has just been put on disk (ModuleArchive).
     * Installed, it gets what an install would have given it and the old
     * version did not have: its new migrations and new permissions. Not
     * installed, there is nothing to do — install() will run everything.
     *
     * The migrations a module ships NOW that it did not ship before are
     * checked the way install() checks all of them: a new file whose name
     * another module already applied would be skipped silently by Laravel.
     *
     * @param  list<string>  $previousMigrations  file names the old version shipped
     */
    public function upgrade(string $id, array $previousMigrations = []): void
    {
        $manifest = $this->find($id) ?? throw new RuntimeException("Module [{$id}] not found on disk.");

        if (! $this->isInstalled($id)) {
            return;
        }

        $this->registerAutoload($manifest);

        if ($path = $manifest->migrationsPath()) {
            $applied = DB::table('migrations')->pluck('migration')->flip();
            $clashes = collect(glob($path.'/*.php') ?: [])
                ->map(fn (string $file) => basename($file, '.php'))
                ->reject(fn (string $name) => in_array($name, $previousMigrations, true))
                ->filter(fn (string $name) => $applied->has($name))
                ->values();

            if ($clashes->isNotEmpty()) {
                throw new RuntimeException(sprintf(
                    'Module [%s] ships new migration(s) whose filename another module already applied: %s.',
                    $id, $clashes->implode(', ')
                ));
            }

            $this->migrate($path);
        }

        foreach ($manifest->permissionNames() as $permission) {
            Permission::findOrCreate($permission);
        }

        DB::table('modules')->where('module_id', $id)->update(['version' => $manifest->version]);
        $this->statuses = $this->blocked = null;

        $this->announce($id, 'updated');
    }

    /**
     * A module's pending migrations, all of them or none.
     *
     * Laravel runs each migration in a transaction of its own, so when the
     * second of three failed the first stayed applied: an install could not
     * be retried (its table was already there), and a replaced module went
     * back to its old folder (ModuleArchive) over a schema half of the new
     * version's. Postgres runs DDL inside a transaction, so one around the
     * whole run makes the set atomic; each migration's own becomes a
     * savepoint. A migration that cannot run inside one (CREATE INDEX
     * CONCURRENTLY) does not belong in a module.
     */
    protected function migrate(string $path): void
    {
        DB::transaction(fn () => Artisan::call('migrate', ['--path' => $this->relativePath($path), '--force' => true]));
    }

    /** Forget what was read from disk — a module folder was added or replaced. */
    public function forget(): void
    {
        $this->discovered = null;
        $this->statuses = $this->blocked = null;
        $this->broken = [];
    }

    /**
     * The module's own installer, if it declared one.
     *
     * Instantiated, never registered: this is a provider being used as a plain
     * object for one call, which is why ModuleLifecycle promises no boot().
     * Autoloading is registered first because the module being installed is not
     * one of the enabled ones the boot pass loaded.
     */
    protected function lifecycle(ModuleManifest $manifest): ?ModuleLifecycle
    {
        $this->registerAutoload($manifest);

        if (! is_a($manifest->provider, ModuleLifecycle::class, true)) {
            return null;
        }

        return new $manifest->provider($this->app);
    }

    /**
     * Laravel identifies a migration by its FILENAME alone, ignoring which
     * directory it came from, so two modules shipping "..._index_foreign_keys.php"
     * are the same migration as far as the `migrations` table is concerned.
     * The second module to install silently skips its own file (its schema is
     * then simply missing), and uninstalling either one runs the wrong down()
     * and deletes the other's record.
     *
     * Nothing in Laravel warns about this — it just does the wrong thing — so
     * installation refuses instead. The fix is always the same: rename the
     * file to something module-specific.
     */
    protected function assertMigrationNamesFree(string $id, string $path): void
    {
        $applied = DB::table('migrations')->pluck('migration')->flip();

        $clashes = collect(glob($path.'/*.php') ?: [])
            ->map(fn (string $file) => basename($file, '.php'))
            ->filter(fn (string $name) => $applied->has($name))
            ->values();

        if ($clashes->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Module [%s] ships migration(s) whose filename is already applied by another module: %s. '
                .'Rename them to something module-specific — Laravel matches migrations by filename, not by path.',
                $id, $clashes->implode(', ')
            ));
        }
    }

    public function uninstall(string $id): void
    {
        $manifest = $this->find($id) ?? throw new RuntimeException("Module [{$id}] not found on disk.");

        if (! $this->isInstalled($id)) {
            throw new RuntimeException("Module [{$id}] is not installed.");
        }

        foreach ($this->dependents($id) as $other) {
            if ($this->isInstalled($other)) {
                throw new RuntimeException("Module [{$other}] depends on [{$id}]; uninstall it first.");
            }
        }

        // First, while its tables and settings are still there to be read.
        $this->lifecycle($manifest)?->uninstall();

        // Its waiting jobs go with it: once the folder's classes stop loading,
        // every one of them would fail with nothing to retry (PLAN §45.6).
        app(\App\Core\Tasks\OrphanJobs::class)->dropForModule($manifest);

        if ($path = $manifest->migrationsPath()) {
            Artisan::call('migrate:reset', ['--path' => $this->relativePath($path), '--force' => true]);
        }

        foreach ($manifest->permissionNames() as $permission) {
            Permission::where('name', $permission)->delete();
        }

        DB::table('settings')->where('module', $id)->delete();
        DB::table('modules')->where('module_id', $id)->delete();

        $this->statuses = $this->blocked = null;

        $this->announce($id, 'uninstalled');
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        if (! $this->isInstalled($id)) {
            throw new RuntimeException("Module [{$id}] is not installed.");
        }

        if ($enabled) {
            $this->assertFitsCore($this->find($id) ?? throw new RuntimeException("Module [{$id}] not found on disk."));
            $this->assertDependenciesEnabled($id);
        } else {
            $this->assertNoEnabledDependents($id);
        }

        DB::table('modules')->where('module_id', $id)->update(['enabled' => $enabled]);
        $this->statuses = $this->blocked = null;

        $this->announce($id, $enabled ? 'enabled' : 'disabled');
    }

    /**
     * Core saying what just happened to the module list.
     *
     * The list lives in a plain table with no model under it, so there is no
     * Eloquent event for anybody to hear — and "who uninstalled payments" is
     * one of the few questions about a shop worth more than the answer to
     * "who changed this price". Announced from HERE rather than from the admin
     * screen for the usual reason: the artisan commands go through this too.
     */
    protected function announce(string $id, string $action): void
    {
        hook_action('core.module.changed', ['module' => $id, 'action' => $action]);
    }

    public function registerAutoload(ModuleManifest $manifest): void
    {
        if (self::$loader === null) {
            self::$loader = require base_path('vendor/autoload.php');
        }

        foreach ($manifest->autoload as $prefix => $dir) {
            $path = $manifest->path.DIRECTORY_SEPARATOR.$dir;

            // Asked again by blocked() and install(): one entry per folder.
            if (! in_array($path, self::$loader->getPrefixesPsr4()[$prefix] ?? [], true)) {
                self::$loader->addPsr4($prefix, $path);
            }
        }
    }

    /**
     * Sidebar groups in the order they render. A manifest's admin_menu item
     * picks one with "group"; anything unlisted falls into "store", which is
     * also what every item written before groups existed gets.
     */
    public const MENU_GROUPS = ['store', 'appearance'];

    /** Admin menu items contributed by enabled modules. */
    public function adminMenu(): array
    {
        $items = [];
        foreach ($this->enabled() as $manifest) {
            // A module's screens are wrapped in its FIRST declared permission
            // (its `<x>.manage`); an item that names no `can` of its own is
            // gated by that one, so the sidebar never offers a screen that
            // would answer 403. A module with no permission must say `can`
            // itself (kits and option groups borrow the catalogue's).
            $gate = $manifest->permissionNames()[0] ?? null;

            foreach ($manifest->adminMenu as $item) {
                $items[] = $item + [
                    'module' => $manifest->id,
                    'position' => $item['position'] ?? 100,
                    'group' => $item['group'] ?? 'store',
                    ...($gate !== null ? ['can' => $gate] : []),
                ];
            }
        }
        usort($items, fn ($a, $b) => $a['position'] <=> $b['position']);

        return $items;
    }

    /**
     * Every permission an INSTALLED module declares, as a row — the rows
     * install() and upgrade() make, for a module whose manifest gained a
     * permission without passing through either (a shop run from git, a
     * developer's checkout): a permission with no row cannot be granted to
     * anyone, and the screens behind it answer 403 to every manager.
     *
     * @return list<string> the permissions created
     */
    public function syncPermissions(): array
    {
        $known = Permission::query()->pluck('name')->flip()->all();
        $created = [];

        foreach ($this->all() as $manifest) {
            if (! $this->isInstalled($manifest->id)) {
                continue;
            }
            foreach ($manifest->permissionNames() as $permission) {
                if (! isset($known[$permission])) {
                    Permission::findOrCreate($permission);
                    $created[] = $permission;
                }
            }
        }

        return $created;
    }

    /**
     * The same items keyed by group, known groups first and in declared order,
     * so the sidebar renders sections without hardcoding which modules exist.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    public function adminMenuByGroup(): array
    {
        $grouped = [];
        foreach ($this->adminMenu() as $item) {
            $grouped[$item['group']][] = $item;
        }

        $ordered = [];
        foreach (self::MENU_GROUPS as $group) {
            if (! empty($grouped[$group])) {
                $ordered[$group] = $grouped[$group];
            }
        }

        // A module may invent its own group; render it after the known ones
        // rather than dropping its menu items on the floor.
        return $ordered + $grouped;
    }

    /** @return list<string> ids of modules on disk that declare $id as a requirement */
    public function dependents(string $id): array
    {
        return array_keys(array_filter(
            $this->all(),
            fn ($other) => $other->id !== $id && in_array($id, $other->requires['modules'] ?? [], true),
        ));
    }

    protected function assertDependenciesEnabled(string $id): void
    {
        foreach ($this->find($id)?->requires['modules'] ?? [] as $dependency) {
            if (! $this->isEnabled($dependency)) {
                throw new RuntimeException("Module [{$id}] requires [{$dependency}] to be installed and enabled.");
            }
        }
    }

    /**
     * A dependent module's code may reference classes from this one, so turning
     * it off underneath a live dependent would fatal on the next request.
     */
    protected function assertNoEnabledDependents(string $id): void
    {
        foreach ($this->dependents($id) as $other) {
            if ($this->isEnabled($other)) {
                throw new RuntimeException("Module [{$other}] depends on [{$id}]; disable it first.");
            }
        }
    }

    protected function relativePath(string $absolute): string
    {
        return str_replace('\\', '/', ltrim(str_replace(base_path(), '', $absolute), '/\\'));
    }
}
