<?php

namespace Torgash\Guard\Errors;

use Illuminate\View\ViewException;
use Throwable;

/**
 * WHERE in the shop's own code a failure happened.
 *
 * The throw site is usually the framework's: every database error is thrown
 * on the same line of Laravel's Connection, every missing class in its
 * container. Fingerprinting by that put every broken query in the shop into
 * ONE row, and pointed "where" at a file nobody here can change. So the
 * origin is the first place in OUR code — app/, modules/, themes/,
 * resources/ — walking out from the throw site; for a template it is the
 * template's own file, named in the message, never the compiled copy under
 * storage/ whose name is a hash.
 *
 * Two entry points that must agree: a live exception, and a row stored before
 * this existed (its message and its trace are strings by then).
 */
class Origin
{
    /** @return array{class: class-string|string, file: ?string, line: ?int} */
    public static function of(Throwable $exception): array
    {
        // A template error is the template's error: the wrapper says nothing.
        $inner = $exception instanceof ViewException && $exception->getPrevious()
            ? $exception->getPrevious()
            : $exception;

        if ($view = self::viewIn($exception->getMessage())) {
            return ['class' => $inner::class, 'file' => $view, 'line' => null];
        }

        $frames = [['file' => $inner->getFile(), 'line' => $inner->getLine()], ...$inner->getTrace()];

        foreach ($frames as $frame) {
            if (isset($frame['file']) && self::isOurs($frame['file'])) {
                return ['class' => $inner::class, 'file' => $frame['file'], 'line' => $frame['line'] ?? null];
            }
        }

        return ['class' => $inner::class, 'file' => $inner->getFile(), 'line' => $inner->getLine()];
    }

    /**
     * The same answer for a stored row: its own file when that is ours, else
     * the template named in the message, else the first of our frames in the
     * stored trace (`file:line Class->method()`).
     *
     * @param  list<string>  $trace
     * @return array{file: ?string, line: ?int}
     */
    public static function fromStored(?string $file, ?int $line, string $message, array $trace): array
    {
        if ($file && self::isOurs($file)) {
            return ['file' => $file, 'line' => $line];
        }

        if ($view = self::viewIn($message)) {
            return ['file' => $view, 'line' => null];
        }

        foreach ($trace as $frame) {
            if (preg_match('/^(.+):(\d+) /', (string) $frame, $m) && self::isOurs($m[1])) {
                return ['file' => $m[1], 'line' => (int) $m[2]];
            }
        }

        return ['file' => $file, 'line' => $line];
    }

    /** The innermost template a view error names: "(View: …/buy-box.blade.php)". */
    public static function viewIn(string $message): ?string
    {
        return preg_match('/\(View: ([^)]+?\.blade\.php)\)/', $message, $m) ? $m[1] : null;
    }

    public static function isOurs(string $file): bool
    {
        $root = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $file = str_replace('\\', '/', $file);

        if (! str_starts_with($file, $root)) {
            return false;
        }

        $relative = substr($file, strlen($root));

        return ! str_starts_with($relative, 'vendor/')
            && ! str_starts_with($relative, 'storage/')
            && ! str_starts_with($relative, 'bootstrap/cache/');
    }
}
