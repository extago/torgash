<?php

namespace Torgash\Speed;

use Closure;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionObject;

/**
 * Whose code is this — a module id, `core`, or `vendor` — read from the FILE a
 * callable was written in. `modules/torgash.catalog/src/…` is the catalogue's,
 * `app/…` is core's.
 *
 * Nothing registers a listener "as" a module (providers boot together, after
 * every one of them registered), so the file is the only honest answer — and
 * it is the one a person would give looking at the code. Memoised per
 * callable: a listener registered at boot is the same object for the life of
 * the process.
 */
class Attribution
{
    /** @var array<string, string> */
    protected array $memo = [];

    protected string $modulesRoot;

    protected string $appRoot;

    public function __construct()
    {
        $this->modulesRoot = self::normalise((string) config('torgash.modules_path', base_path('modules'))).'/';
        $this->appRoot = self::normalise(app_path()).'/';
    }

    public function of(callable $callable): string
    {
        $key = match (true) {
            $callable instanceof Closure => 'c'.spl_object_id($callable),
            is_array($callable) => (is_object($callable[0]) ? get_class($callable[0]) : $callable[0]).'::'.$callable[1],
            is_object($callable) => get_class($callable),
            default => (string) $callable,
        };

        return $this->memo[$key] ??= $this->forFile($this->fileOf($callable));
    }

    public function forFile(?string $file): string
    {
        if ($file === null) {
            return 'core';
        }

        $file = self::normalise($file);

        if (str_starts_with($file, $this->modulesRoot)) {
            return strtok(substr($file, strlen($this->modulesRoot)), '/') ?: 'core';
        }

        return str_starts_with($file, $this->appRoot) ? 'core' : 'vendor';
    }

    /** The module a route's controller lives in — who owns the page. */
    public function ofAction(?string $action): string
    {
        if (! $action || $action === 'Closure') {
            return 'core';
        }

        $class = strtok($action, '@');

        return class_exists($class)
            ? ($this->memo['route:'.$class] ??= $this->forFile((new \ReflectionClass($class))->getFileName() ?: null))
            : 'core';
    }

    protected function fileOf(callable $callable): ?string
    {
        try {
            $file = match (true) {
                $callable instanceof Closure => (new ReflectionFunction($callable))->getFileName(),
                is_array($callable) => (new ReflectionMethod($callable[0], $callable[1]))->getFileName(),
                is_object($callable) => (new ReflectionObject($callable))->getFileName(),
                is_string($callable) && str_contains($callable, '::') => (new ReflectionMethod($callable))->getFileName(),
                default => (new ReflectionFunction($callable))->getFileName(),
            };
        } catch (\ReflectionException) {
            return null;
        }

        return $file ?: null;
    }

    public static function normalise(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
