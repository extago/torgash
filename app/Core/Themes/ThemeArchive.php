<?php

namespace App\Core\Themes;

use App\Core\Support\CodeArchive;
use App\Core\Support\SafeMode;
use App\Core\System\CodeChanged;
use App\Models\Storefront;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * A theme arriving as a .zip — put into themes/, where the Themes screen
 * sees it at once and a storefront can put it on — and a theme taken away
 * again. Read the careful way of every code archive (CodeArchive); what is
 * the theme's own:
 *
 * - **A theme IS its folder.** The folder's name comes from the archive's one
 *   top-level folder (`crystal/theme.json` → `themes/crystal`), or from the
 *   theme's name when theme.json lies at the root. Letters, digits, `-`, `_`.
 * - **A theme of the shop's own names its author.** `Distribution` tells the
 *   engine's themes from everybody else's by `author` (ThemeManager
 *   ::isBundledMeta): one without is replaced whole by the next core update,
 *   so an archive without an author — or signed "Torgash" — is refused here
 *   rather than silently lost later.
 * - **The engine's themes are not touched from here.** `default` and
 *   `skeleton` arrive and leave with core updates only: an archive naming
 *   their folder is refused, and remove() refuses them too — along with the
 *   theme the workers boot with, the one safe mode wears, any theme a
 *   storefront has on, and any theme another theme on disk is based on.
 * - **Only forwards, staged, kept**: a theme already here is replaced only by
 *   a higher version; the old folder goes to `storage/app/theme-backups/`
 *   (as does a removed one) and is put back if the swap fails.
 * - **A theme's settings outlive its folder**: the rows a storefront saved
 *   for it stay, so a theme put in again finds them.
 *
 * Who may do this at all is the controller's question — the super group
 * only, since uploading or deleting code is more than managing themes.
 */
class ThemeArchive extends CodeArchive
{
    public const DIR_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,60}$/';

    public function __construct(protected ThemeManager $themes) {}

    protected function marker(): string
    {
        return 'theme.json';
    }

    /**
     * @return array{dir: string, name: string, version: string, replaced: ?string}
     */
    public function put(string $zipPath): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException(__('This is not a zip archive.'));
        }

        try {
            [$prefix, $files] = $this->inspect($zip);
            $meta = json_decode((string) $zip->getFromName($prefix.'theme.json'), true);

            if (! is_array($meta) || ! is_string($meta['name'] ?? null) || trim($meta['name']) === '') {
                throw new RuntimeException(__('The archive\'s theme.json does not name a theme (a "name" at least).'));
            }

            if (ThemeManager::isBundledMeta($meta)) {
                throw new RuntimeException(__('The theme.json names no author (or the author "Torgash"). A theme without an author of its own is treated as the engine\'s and replaced whole by the next update — add "author": {"name": "…"} to it.'));
            }

            $dir = $prefix !== '' ? rtrim($prefix, '/') : Str::slug($meta['name'], '-');

            if (! preg_match(self::DIR_PATTERN, $dir)) {
                throw new RuntimeException(__('":dir" cannot be a theme\'s folder: lowercase letters, digits, "-" and "_", up to 61 characters.', ['dir' => Str::limit($dir, 60)]));
            }

            if ($this->themes->isBundled($dir) && is_dir($this->path($dir))) {
                throw new RuntimeException(__('":dir" is a theme that ships with the engine; it is updated with the engine, not uploaded.', ['dir' => $dir]));
            }

            $parent = $meta['parent'] ?? null;
            if ($parent !== null && (! is_string($parent) || $parent === $dir || ! is_dir($this->path($parent)))) {
                throw new RuntimeException(__('The theme is based on ":parent", which is not in themes/. Put that theme in first.', ['parent' => is_string($parent) ? Str::limit($parent, 60) : '?']));
            }

            $version = (string) ($meta['version'] ?? '0.0.0');
            $existing = is_dir($this->path($dir)) ? $this->themes->meta($dir) : null;
            $existingVersion = $existing === null ? null : (string) ($existing['version'] ?? '0.0.0');

            if ($existing !== null && version_compare($version, $existingVersion, '<=')) {
                throw new RuntimeException(__(':theme :current is already here; the archive holds :version. Only a newer version replaces it.', [
                    'theme' => $dir, 'current' => $existingVersion, 'version' => $version,
                ]));
            }

            $staging = storage_path('app/theme-uploads/'.Str::uuid());
            $this->extract($zip, $prefix, $files, $staging);
        } catch (Throwable $e) {
            if (isset($staging)) {
                File::deleteDirectory($staging);
            }

            throw $e;
        } finally {
            $zip->close();
        }

        try {
            $existing !== null
                ? $this->replace($dir, $existingVersion, $staging)
                : $this->place($dir, $staging);
        } finally {
            File::deleteDirectory($staging);
        }

        $this->changed();

        return ['dir' => $dir, 'name' => $meta['name'], 'version' => $version, 'replaced' => $existingVersion];
    }

    /**
     * Why the theme may not be taken away, or null when it may. Asked by the
     * screen (no button) and by remove() (a refusal) alike.
     */
    public function undeletable(string $dir): ?string
    {
        if (! is_dir($this->path($dir))) {
            return __('There is no theme ":dir".', ['dir' => $dir]);
        }

        if ($this->themes->isBundled($dir)) {
            return __('":dir" ships with the engine and cannot be deleted.', ['dir' => $dir]);
        }

        if ($dir === config('torgash.default_theme', 'default') || $dir === SafeMode::THEME) {
            return __('":dir" is the theme the engine falls back on and cannot be deleted.', ['dir' => $dir]);
        }

        $wearing = Storefront::query()->where('theme', $dir)->pluck('name')->all();
        if ($wearing !== []) {
            return __('":dir" is worn by :storefronts — put another theme on first.', ['dir' => $dir, 'storefronts' => implode(', ', $wearing)]);
        }

        $children = $this->themes->children($dir);
        if ($children !== []) {
            return __('":dir" is what :themes are based on — delete those first.', ['dir' => $dir, 'themes' => implode(', ', $children)]);
        }

        return null;
    }

    /** The folder goes to storage/app/theme-backups/, not to /dev/null. */
    public function remove(string $dir): void
    {
        if (! preg_match(self::DIR_PATTERN, $dir)) {
            throw new RuntimeException(__('There is no theme ":dir".', ['dir' => Str::limit($dir, 60)]));
        }

        if ($why = $this->undeletable($dir)) {
            throw new RuntimeException($why);
        }

        $backup = $this->backupPath($dir, (string) ($this->themes->meta($dir)['version'] ?? '0.0.0'));

        if (! File::moveDirectory($this->path($dir), $backup)) {
            throw new RuntimeException(__('Could not move the theme out of themes/ — check that the folder is writable.'));
        }

        $this->changed();
    }

    protected function place(string $dir, string $staging): void
    {
        if (! File::moveDirectory($staging, $this->path($dir))) {
            throw new RuntimeException(__('Could not write the theme into themes/ — check that the folder is writable.'));
        }
    }

    protected function replace(string $dir, string $version, string $staging): void
    {
        $target = $this->path($dir);
        $backup = $this->backupPath($dir, $version);

        if (! File::moveDirectory($target, $backup)) {
            throw new RuntimeException(__('Could not set the current version aside — check that themes/ is writable.'));
        }

        if (! File::moveDirectory($staging, $target)) {
            File::deleteDirectory($target);
            File::moveDirectory($backup, $target);

            throw new RuntimeException(__('Could not write the theme into themes/ — check that the folder is writable.'));
        }
    }

    protected function path(string $dir): string
    {
        return config('torgash.themes_path').DIRECTORY_SEPARATOR.$dir;
    }

    protected function backupPath(string $dir, string $version): string
    {
        $backup = storage_path('app/theme-backups/'.$dir.'-'.$version.'-'.now()->format('Ymd-His'));
        File::ensureDirectoryExists(dirname($backup));

        return $backup;
    }

    /**
     * The view finder has found files under the old folder, the stylesheet
     * holds the old templates' classes, the workers run the old code: the
     * same refresh as after any change of code.
     */
    protected function changed(): void
    {
        $this->themes->forget();
        app(CodeChanged::class)->refresh();
    }
}
