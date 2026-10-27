<?php

namespace App\Core\Support;

use RuntimeException;

/**
 * Writes named keys of the .env file and leaves every other line — comments,
 * order, blank lines, keys it was not asked about — exactly as it was.
 *
 * For the few choices that cannot live in the settings table because they
 * are needed before it can be read (where the cache is, where sessions are)
 * or because a secret in a table is in every database dump. Written to a
 * temporary file beside it and renamed over, so a request reading .env never
 * sees half of one.
 */
class EnvFile
{
    public function __construct(protected ?string $path = null)
    {
        $this->path ??= app()->environmentFilePath();
    }

    public function writable(): bool
    {
        return is_file($this->path) && is_writable($this->path);
    }

    public function get(string $key): ?string
    {
        $raw = (string) @file_get_contents($this->path);

        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $raw, $m)) {
            return null;
        }

        $value = trim($m[1]);

        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
            if ($m[1][0] === '"') {
                $value = str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
            }
        }

        return $value;
    }

    /** @param  array<string, string|int|null>  $values */
    public function set(array $values): void
    {
        if (! $this->writable()) {
            throw new RuntimeException(__('The .env file cannot be written.'));
        }

        $raw = (string) file_get_contents($this->path);
        $eol = str_contains($raw, "\r\n") ? "\r\n" : "\n";

        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new RuntimeException("Not an environment key: {$key}");
            }

            $line = $key.'='.self::quote($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            $raw = preg_match($pattern, $raw)
                ? preg_replace_callback($pattern, fn () => $line, $raw, 1)
                : rtrim($raw, "\r\n").$eol.$line.$eol;
        }

        $tmp = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmp, $raw, LOCK_EX);

        if (! @rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException(__('The .env file cannot be written.'));
        }
    }

    /**
     * Bare when it is plainly safe, single quotes otherwise (literal in
     * phpdotenv — no `${…}` expansion inside a password), double quotes only
     * for a value that itself holds a single quote.
     */
    public static function quote(string|int|null $value): string
    {
        $value = (string) $value;

        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException('A value in .env cannot span lines.');
        }

        if ($value !== '' && preg_match('/^[A-Za-z0-9_.:\/@+-]+$/', $value)) {
            return $value;
        }

        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
