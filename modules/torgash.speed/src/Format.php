<?php

namespace Torgash\Speed;

/** How a duration reads: "0.4 ms", "86 ms", "1.24 s". */
class Format
{
    /** The five parts of a request, in the order they are stacked and colored. */
    public const PARTS = [
        'boot' => 'Engine start',
        'db' => 'Database',
        'http' => 'Outside calls',
        'hooks' => 'Module hooks',
        'rest' => 'Code & templates',
    ];

    /** Which part a timeline span belongs to. */
    public const SPAN_PART = ['boot' => 'boot', 'query' => 'db', 'http' => 'http', 'hook' => 'hooks'];

    public static function ms(float|int|null $ms): string
    {
        $ms = (float) $ms;

        return match (true) {
            $ms >= 1000 => number_format($ms / 1000, 2).' s',
            $ms >= 10 => number_format($ms).' ms',
            default => number_format($ms, 1).' ms',
        };
    }

    public static function percent(float $share): string
    {
        return $share >= 0.1 ? round($share * 100).'%' : number_format($share * 100, 1).'%';
    }

    public static function bytes(int|float $bytes): string
    {
        return number_format($bytes / 1048576, 1).' MB';
    }
}
