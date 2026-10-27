<?php

namespace App\Core\Support;

use InvalidArgumentException;

/**
 * Lookup for the vendored Lucide set (ISC), see tools/vendor-lucide.php.
 *
 * All ~2000 icons are on disk, so a module names whatever it needs from
 * https://lucide.dev/icons without the core maintaining a whitelist and
 * without anyone patching core to add one.
 */
class Icons
{
    /** @var array<string, ?string> name => svg markup, or null when missing */
    protected static array $cache = [];

    /** Raw <svg> markup for $name, or null if there is no such icon. */
    public static function markup(string $name): ?string
    {
        return self::$cache[$name] ??= self::read($name);
    }

    public static function exists(string $name): bool
    {
        return self::markup($name) !== null;
    }

    /** Every available name, sorted — for docs and admin pickers (e.g. a <datalist>). */
    public static function names(): array
    {
        $names = array_map(
            fn (string $path) => basename($path, '.svg'),
            glob(self::directory().'/*.svg') ?: [],
        );
        sort($names);

        return $names;
    }

    public static function directory(): string
    {
        return resource_path('icons/lucide');
    }

    public static function missing(string $name): InvalidArgumentException
    {
        return new InvalidArgumentException(
            "Unknown icon [{$name}]. Icon names are Lucide names — look them up at "
            .'https://lucide.dev/icons or in '.self::directory().'.',
        );
    }

    protected static function read(string $name): ?string
    {
        // Names arrive from module manifests as data, so nothing but [a-z0-9-]
        // is ever allowed to reach the filesystem.
        if (! preg_match('/^[a-z0-9-]+$/', $name)) {
            return null;
        }

        $file = self::directory().DIRECTORY_SEPARATOR.$name.'.svg';

        return is_file($file) ? (string) file_get_contents($file) : null;
    }
}
