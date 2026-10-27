<?php

namespace App\Core\Updates;

use App\Core\Modules\ModuleManifest;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * What an update owns (PLAN §45.7а): the paths of an install that a release
 * replaces whole, and nothing else.
 *
 * Core and the BUNDLED modules and themes (no `author` — they ship with core
 * and carry its version); a module or theme somebody else wrote is updated
 * its own way and never touched here. `.env`, `storage`, `public/storage`,
 * a module's `public/1c_exchange.php` are the shop's and are not on the list.
 * Inside an owned directory the shop changes nothing — that is what a theme
 * is for — because the whole directory is replaced.
 */
class Distribution
{
    public const DIRS = ['app', 'config', 'database', 'lang', 'resources', 'routes', 'vendor', 'data/geo', 'public/assets'];

    public const FILES = [
        'artisan', 'composer.json', 'composer.lock', 'bootstrap/app.php', 'bootstrap/providers.php',
        'public/index.php', 'public/.htaccess', 'public/favicon.ico',
    ];

    public function __construct(protected string $root) {}

    /** @return list<string> the owned paths present under this root, relative, with forward slashes */
    public function paths(): array
    {
        $paths = [];

        foreach (self::DIRS as $dir) {
            if (is_dir($this->root.'/'.$dir)) {
                $paths[] = $dir;
            }
        }
        foreach (self::FILES as $file) {
            if (is_file($this->root.'/'.$file)) {
                $paths[] = $file;
            }
        }

        foreach (glob($this->root.'/modules/*/manifest.json') ?: [] as $manifest) {
            try {
                if (ModuleManifest::fromFile($manifest)->isBundled()) {
                    $paths[] = 'modules/'.basename(dirname($manifest));
                }
            } catch (Throwable) {
                // A broken manifest is nobody's to replace.
            }
        }

        foreach (glob($this->root.'/themes/*/theme.json') ?: [] as $theme) {
            $data = json_decode((string) file_get_contents($theme), true);
            // The engine's own theme is signed "Torgash"; a theme by anybody
            // else (Crystal is Extago's) is updated on its own.
            if (is_array($data) && (empty($data['author']) || ($data['author']['name'] ?? null) === 'Torgash')) {
                $paths[] = 'themes/'.basename(dirname($theme));
            }
        }

        return $paths;
    }

    /**
     * Every file under the given owned paths: relative path => absolute path.
     *
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    public function files(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $absolute = $this->root.'/'.$path;
            if (is_file($absolute)) {
                $files[$path] = $absolute;

                continue;
            }
            if (! is_dir($absolute)) {
                continue;
            }

            $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($walk as $file) {
                if ($file->isFile()) {
                    $relative = $path.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($absolute) + 1));
                    $files[$relative] = $file->getPathname();
                }
            }
        }

        ksort($files);

        return $files;
    }

    /** A path a release may name: relative, forward slashes, no `..`, inside an owned path. */
    public static function safePath(string $path, array $owned): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')
            || preg_match('~(^|/)\.\.?(/|$)~', $path) || preg_match('~^[A-Za-z]:~', $path)) {
            return false;
        }

        foreach ($owned as $root) {
            if ($path === $root || str_starts_with($path, $root.'/')) {
                return true;
            }
        }

        return false;
    }

    /** Whether a path may be OWNED at all — a release cannot claim `.env` or `storage`. */
    public static function ownable(string $path): bool
    {
        return in_array($path, self::DIRS, true) || in_array($path, self::FILES, true)
            || preg_match('~^(modules|themes)/[A-Za-z0-9][A-Za-z0-9._-]*$~', $path) === 1;
    }
}
