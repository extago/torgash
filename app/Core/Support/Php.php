<?php

namespace App\Core\Support;

use Symfony\Component\Process\PhpExecutableFinder;

/**
 * How to start PHP from PHP — for the update's second half, the rollback,
 * `composer audit`, a cron line shown to the owner.
 *
 * Never the bare `PHP_BINARY` constant: it is the binary THIS process runs
 * in, and that is not always a PHP. Under FrankenPHP it is `frankenphp`,
 * whose own command line answers `frankenphp artisan …` with "unknown
 * command" — found on the first FrankenPHP server, where an update would
 * have switched the shop to maintenance, swapped the files, failed to start
 * `torgash:update-finish` and failed the rollback the same way.
 *
 * In order: the `PHP_BINARY` environment variable when the server sets one
 * (Laravel's scheduler reads the same, so one setting serves both); then
 * FrankenPHP's own CLI, `frankenphp php-cli`; then what Symfony finds.
 */
class Php
{
    /**
     * @param  string|null  $binary  the running binary — PHP_BINARY; a parameter so a test can be FrankenPHP
     * @return list<string> the command to put before a script and its arguments
     */
    public static function command(?string $binary = null): array
    {
        $binary ??= PHP_BINARY;

        $env = getenv('PHP_BINARY');
        if (is_string($env) && $env !== '') {
            return [$env];
        }

        if (str_starts_with(strtolower(basename($binary)), 'frankenphp')) {
            return [$binary, 'php-cli'];
        }

        return [(new PhpExecutableFinder)->find(false) ?: 'php'];
    }

    /** The same, as one line for a shell or a cron table. */
    public static function line(): string
    {
        return implode(' ', array_map(
            fn (string $part) => preg_match('/^[\w\/.:\\\\-]+$/', $part) ? $part : escapeshellarg($part),
            self::command(),
        ));
    }
}
