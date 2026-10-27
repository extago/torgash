<?php

namespace Torgash\Search;

/**
 * "ytyneb" is "ноутбук" typed with the keyboard left on English.
 *
 * Keys, not letters: each Latin key maps to the Cyrillic letter on the same
 * key of the ЙЦУКЕН layout (Ukrainian differs in three keys — і, ї, є — and
 * gets its own map), and back. Asked only when a query found nothing as
 * typed, so a real English word is never "corrected" into Russian.
 */
class Layout
{
    protected const EN = 'qwertyuiop[]asdfghjkl;\'zxcvbnm,.`';

    protected const RU = 'йцукенгшщзхъфывапролджэячсмитьбюё';

    protected const UK = 'йцукенгшщзхїфівапролджєячсмитьбю\'';

    /**
     * Every other reading of the query worth trying, in order.
     *
     * @return list<string>
     */
    public static function alternatives(string $query): array
    {
        $out = [];

        foreach ([[self::EN, self::RU], [self::EN, self::UK], [self::RU, self::EN], [self::UK, self::EN]] as [$from, $to]) {
            $converted = self::convert($query, $from, $to);

            if ($converted !== $query && ! in_array($converted, $out, true)) {
                $out[] = $converted;
            }
        }

        return $out;
    }

    protected static function convert(string $text, string $from, string $to): string
    {
        $map = array_combine(mb_str_split($from), mb_str_split($to));
        $changed = false;
        $out = '';

        foreach (mb_str_split(mb_strtolower($text)) as $char) {
            if (isset($map[$char])) {
                $out .= $map[$char];
                $changed = true;
            } else {
                $out .= $char;
            }
        }

        return $changed ? $out : $text;
    }
}
