<?php

namespace App\Core\Themes;

use App\Core\Modules\ModuleManifest;
use App\Core\Zones\ZoneRegistry;
use Illuminate\Support\Facades\View;

/**
 * Template override cascade: storefront theme → parent theme(s) → module → core.
 *
 * Views of module "torgash.hello" are referenced as "torgash.hello::name".
 * A theme overrides them by placing files in themes/<theme>/views/modules/torgash.hello/.
 * Plain (non-namespaced) views are overridden via themes/<theme>/views/.
 *
 * The cascade is wired at BOOT, from the configured default theme — and the
 * storefront's own theme is only known later, when ResolveStorefront has read
 * the request. So `setActive()` with a different theme takes the wiring apart
 * and lays it again: the view paths it added, every module's namespace and
 * the theme's translations and zones. Before this it only changed a property
 * nothing read any more, and picking a theme on a storefront changed nothing
 * at all (found by the first theme that was not `default`).
 */
class ThemeManager
{
    protected ?string $active = null;

    /** @var list<string> view directories this class put in front of the finder */
    protected array $viewPaths = [];

    /** @var array<string, ModuleManifest> modules whose namespace carries theme overrides */
    protected array $manifests = [];

    /** Set by the storefront resolver; falls back to config. */
    public function setActive(string $theme): void
    {
        if ($theme === $this->active()) {
            return;
        }

        $this->active = $theme;
        $this->rewire();
    }

    /**
     * Back to the theme the process booted with — the start of a request in a
     * persistent worker (App\Core\Support\WorkerMode).
     *
     * The view finder this rewires is shared by every request of a worker, so
     * a storefront on its own theme would otherwise leave that theme in place
     * for whatever comes next — the admin, the API, a letter — none of which
     * passes through ResolveStorefront to set it back.
     */
    public function reset(): void
    {
        if ($this->active !== null && $this->active !== config('torgash.default_theme', 'default')) {
            $this->setActive(config('torgash.default_theme', 'default'));
        }

        $this->active = null;
    }

    public function active(): string
    {
        return $this->active ?? config('torgash.default_theme', 'default');
    }

    /** Theme chain from the active theme up through its parents. */
    public function chain(?string $theme = null): array
    {
        $chain = [];
        $current = $theme ?? $this->active();
        $base = config('torgash.themes_path');

        while ($current !== null && ! in_array($current, $chain)) {
            if (! is_dir($base.DIRECTORY_SEPARATOR.$current)) {
                break;
            }
            $chain[] = $current;
            $meta = $this->meta($current);
            $current = $meta['parent'] ?? null;
        }

        return $chain;
    }

    public function meta(string $theme): array
    {
        $file = config('torgash.themes_path').DIRECTORY_SEPARATOR.$theme.DIRECTORY_SEPARATOR.'theme.json';

        return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    }

    /**
     * Every theme on disk, for the storefront form's picker.
     *
     * @return array<string, string> directory => display name
     */
    public function available(): array
    {
        $out = [];

        foreach (glob(config('torgash.themes_path').DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $dir) {
            $key = basename($dir);
            $meta = $this->meta($key);
            $out[$key] = (string) ($meta['name'] ?? $key);
        }

        return $out;
    }

    /** Prepend theme view directories so they win over resources/views. */
    public function registerViewPaths(): void
    {
        $base = config('torgash.themes_path');

        foreach (array_reverse($this->chain()) as $theme) {
            $path = $base.DIRECTORY_SEPARATOR.$theme.DIRECTORY_SEPARATOR.'views';
            if (is_dir($path)) {
                View::getFinder()->prependLocation($path);
                $this->viewPaths[] = $path;
            }
        }
    }

    /** Register a module's view namespace with theme overrides first. */
    public function registerModuleViews(ModuleManifest $manifest): void
    {
        $this->manifests[$manifest->id] = $manifest;
        $paths = [];
        $base = config('torgash.themes_path');

        foreach ($this->chain() as $theme) {
            $override = $base.DIRECTORY_SEPARATOR.$theme
                .DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'modules'
                .DIRECTORY_SEPARATOR.$manifest->id;
            if (is_dir($override)) {
                $paths[] = $override;
            }
        }

        if ($own = $manifest->viewsPath()) {
            $paths[] = $own;
        }

        if ($paths !== []) {
            View::replaceNamespace($manifest->id, $paths);
        }
    }

    /**
     * Lay the cascade again for the theme now active: take out only the paths
     * this class added (core's resources/views stays), prepend the new chain,
     * re-point every module namespace, and forget what the finder already
     * found — it caches a view name's file for the rest of the process.
     * Translations and zones are ADDED for the new chain; the old theme's stay
     * loaded, which is harmless for the usual case of a child of `default`.
     */
    protected function rewire(): void
    {
        $finder = View::getFinder();
        $finder->setPaths(array_values(array_diff($finder->getPaths(), $this->viewPaths)));
        $this->viewPaths = [];

        $this->registerViewPaths();

        foreach ($this->manifests as $manifest) {
            $this->registerModuleViews($manifest);
        }

        $finder->flush();

        foreach ($this->chain() as $theme) {
            $lang = config('torgash.themes_path').DIRECTORY_SEPARATOR.$theme.DIRECTORY_SEPARATOR.'lang';

            if (is_dir($lang)) {
                app('translator')->addJsonPath($lang);
            }
        }

        app(ZoneRegistry::class)->registerFromThemes();

        // Its schema is the chain's, and the chain has just changed.
        if (app()->resolved(ThemeSettings::class)) {
            app(ThemeSettings::class)->flush();
        }
    }
}
