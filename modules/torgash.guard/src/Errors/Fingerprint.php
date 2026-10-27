<?php

namespace Torgash\Guard\Errors;

use Throwable;

/**
 * What makes two failures the same failure.
 *
 * Everything about the error screen depends on this being right in one
 * direction and forgiving in the other. Too coarse and two unrelated bugs share
 * a row, so the second one is invisible; too fine and one bug becomes four
 * hundred rows and the screen is useless in a different way.
 *
 * An EXCEPTION is identified by its class and where in OUR code it happened
 * (Origin) — not by its message, because the message usually carries the
 * particular ("no query results for model [Order] 41") while the class and the
 * line carry the bug. Not by the throw site either: that is usually a line of
 * the framework, shared by every query or every template in the shop.
 *
 * A plain log line has no such structure, so the message itself is the
 * identity — with runs of digits replaced, because "User 512 not found" and
 * "User 513 not found" are one problem and would otherwise be one row per
 * customer. Only digits: guessing more (quoted strings, uuids, paths) starts
 * merging things that are genuinely different, and the rule has to be one a
 * shop owner can be told in a sentence.
 */
class Fingerprint
{
    public static function of(string $level, string $message, ?Throwable $exception): string
    {
        if ($exception) {
            $origin = Origin::of($exception);

            return sha1(implode('|', [$origin['class'], $origin['file'], $origin['line']]));
        }

        return sha1($level.'|'.self::normalise($message));
    }

    /** Runs of digits collapsed, so an id in the text does not fork the group. */
    public static function normalise(string $message): string
    {
        return preg_replace('/\d+/', '#', $message) ?? $message;
    }
}
