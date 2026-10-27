<?php

namespace Torgash\NovaPoshta\Sync;

/**
 * Making two spellings of the same place comparable.
 *
 * The two sides say the same thing differently, verified on the real rows:
 * the carrier writes the oblast as "Полтавська" and the raion as "Полтавський",
 * our registry writes "Полтавська область" and "Полтавський район"; either may
 * carry a straight apostrophe, a curly one, or none at all
 * (Слов'янськ / Слов’янськ / Словянськ); Russian spellings differ by ё/е.
 *
 * So: fold case, drop the administrative words, drop apostrophes entirely
 * rather than trying to normalise which one was typed, and collapse spacing.
 * Hyphens stay — Івано-Франківськ is not Івано Франківськ to anybody.
 */
class Normalizer
{
    /** Words that say what kind of unit this is, in both languages, and their short forms. */
    private const NOISE = [
        'область', 'обл', 'районі', 'район', 'р-н', 'область', 'области',
        'місто', 'м', 'город', 'смт', 'село', 'селище', 'с',
    ];

    public static function name(string $value): string
    {
        $value = mb_strtolower(trim($value));

        // Apostrophes are noise: three characters spell the same sound.
        $value = str_replace(["'", 'ʼ', '’', '‘', '`', '´'], '', $value);
        $value = str_replace('ё', 'е', $value);

        // Bracketed clarifications ("Київ (Київська обл)") are not the name.
        $value = preg_replace('/\s*\(.*$/u', '', $value) ?? $value;

        $words = preg_split('/[\s,\.]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn ($word) => ! in_array($word, self::NOISE, true)));

        return implode(' ', $words);
    }

    /** One lookup key for "this name in this oblast". */
    public static function key(string $name, string $area): string
    {
        return self::name($name).'|'.self::name($area);
    }

    /** The same, narrowed by raion — the only thing that separates namesakes. */
    public static function keyWithRegion(string $name, string $area, ?string $region): string
    {
        return self::key($name, $area).'|'.self::name((string) $region);
    }
}
