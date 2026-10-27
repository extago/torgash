<?php

namespace Torgash\Catalog\Support;

use Illuminate\Support\Str;

/**
 * A catalogue address from a name or a typed slug — Str::slug(), except that
 * a "+" is a word: "iPhone 16+" and "Wi-Fi 6+" lost it and came out the same
 * as "iPhone 16" (the first live shop's report, 2026-10-27).
 */
class Slugs
{
    public static function make(string $text): string
    {
        return Str::slug(preg_replace('/\s*\+\s*/u', ' plus ', $text) ?? $text);
    }
}
