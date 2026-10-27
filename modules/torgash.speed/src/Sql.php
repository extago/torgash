<?php

namespace Torgash\Speed;

/**
 * A query's SHAPE, so that "the same query" can be counted: `where id = 5`
 * and `where id = 6` are one question asked twice, and a list of forty ids is
 * the same question as a list of three.
 *
 * And where it was asked from — the first frame in the shop's own code, or
 * the TEMPLATE, when the frame is a compiled Blade view: a query asked from a
 * template is the classic N+1 (a card lazy-loading its photo), and
 * `storage/framework/views/8f2c….php:41` points nobody anywhere.
 */
class Sql
{
    public static function shape(string $sql): string
    {
        $sql = preg_replace("/'(?:[^']|'')*'/", '?', $sql);
        $sql = preg_replace('/(?<![\w$."])-?\d+(?:\.\d+)?\b/', '?', (string) $sql);
        // in (?, ?, ?) — any length is one shape.
        $sql = preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?…)', (string) $sql);

        return trim((string) preg_replace('/\s+/', ' ', (string) $sql));
    }

    public static function fingerprint(string $shape): string
    {
        return md5($shape);
    }

    /**
     * The first frame that is the shop's own code — not the framework, not a
     * vendor package, not this module watching.
     */
    public static function caller(): ?string
    {
        $base = Attribution::normalise(base_path()).'/';
        $views = Attribution::normalise(storage_path('framework/views')).'/';
        $self = Attribution::normalise(dirname(__DIR__)).'/';

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
            $file = isset($frame['file']) ? Attribution::normalise($frame['file']) : null;

            if ($file === null || str_starts_with($file, $self) || str_contains($file, '/vendor/')) {
                continue;
            }

            if (str_starts_with($file, $views)) {
                $view = self::viewSource($frame['file']);

                return $view ? 'view: '.$view : null;
            }

            if (str_starts_with($file, $base.'app/') || str_starts_with($file, $base.'modules/') || str_starts_with($file, $base.'themes/')) {
                return substr($file, strlen($base)).':'.($frame['line'] ?? '?');
            }
        }

        return null;
    }

    /**
     * Blade ends every compiled file with `/**PATH <source> ENDPATH**\/` — the
     * template the query really came from. Read from the file's tail only.
     */
    protected static function viewSource(string $compiled): ?string
    {
        $handle = @fopen($compiled, 'r');

        if (! $handle) {
            return null;
        }

        fseek($handle, -600, SEEK_END);
        $tail = (string) fread($handle, 600);
        fclose($handle);

        if (! preg_match('#/\*\*PATH (.+?) ENDPATH\*\*/#', $tail, $m)) {
            return null;
        }

        $base = Attribution::normalise(base_path()).'/';
        $path = Attribution::normalise($m[1]);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
