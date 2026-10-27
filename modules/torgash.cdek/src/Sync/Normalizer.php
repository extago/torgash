<?php

namespace Torgash\Cdek\Sync;

/**
 * Making two spellings of the same place comparable.
 *
 * CDEK writes "Новосибирск" with the region "Новосибирская область", and
 * "Татарстан" for what our registry calls "Республика Татарстан"; either side
 * may write ё or е. So: fold case, ё → е, drop bracketed clarifications and the
 * words that only say what KIND of unit this is, collapse spacing. Hyphens
 * stay — Ростов-на-Дону is not Ростов на Дону to anybody.
 */
class Normalizer
{
    /** Words that say what kind of unit this is, and their short forms. */
    private const NOISE = [
        'город', 'г', 'поселок', 'посёлок', 'пос', 'п', 'пгт', 'рп', 'село', 'с',
        'деревня', 'д', 'станица', 'ст-ца', 'хутор', 'х', 'аул', 'мкр', 'микрорайон',
        'область', 'обл', 'край', 'республика', 'респ', 'автономный', 'автономная',
        'округ', 'ао', 'район', 'р-н', 'городской', 'муниципальный',
    ];

    public static function name(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace('ё', 'е', $value);

        // "Новосибирск (Новосибирская обл.)" — the bracket is not the name.
        $value = preg_replace('/\s*\(.*$/u', '', $value) ?? $value;
        // "Кемеровская область - Кузбасс": what follows a spaced dash is a
        // second name for the same region, which one side has and the other
        // does not.
        $value = preg_replace('/\s+[-–—]\s+.*$/u', '', $value) ?? $value;

        $words = preg_split('/[\s,\.]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn ($word) => ! in_array($word, self::NOISE, true)));

        return implode(' ', $words);
    }
}
