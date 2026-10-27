<?php

namespace Torgash\Search;

use Illuminate\Support\HtmlString;

/**
 * What was typed, in bold, inside a name — the part that actually matched.
 *
 * The engine matches the START of a word ("ноут" → "Ноутбук") or a word's
 * stem ("ноутбуки" → "Ноутбук"), never the middle of one, so that is all this
 * marks: a word of the text starting with a word of the query, or — for
 * another form of the word — the common beginning they share, when it is most
 * of what was typed. Marking "ку" inside "кутикулы" would show a match the
 * engine did not make.
 *
 * The text is escaped here and only <b> is added, so the result is safe to
 * print with {!! !!} — and it is an HtmlString, so {{ }} prints it too.
 */
class Highlight
{
    /**
     * @param  list<string>  $words  the query's words, lower case
     */
    public static function html(?string $text, array $words): HtmlString
    {
        $text = (string) $text;
        $words = array_values(array_filter($words, fn ($w) => $w !== ''));

        if ($text === '' || $words === []) {
            return new HtmlString(e($text));
        }

        $out = '';
        $offset = 0;

        // Every run of letters and digits is a word of the text; what lies
        // between is copied as it is.
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $m, PREG_OFFSET_CAPTURE);

        foreach ($m[0] as [$word, $byte]) {
            $out .= e(substr($text, $offset, $byte - $offset));
            $length = self::matched(mb_strtolower($word), $words);

            $out .= $length > 0
                ? '<b>'.e(mb_substr($word, 0, $length)).'</b>'.e(mb_substr($word, $length))
                : e($word);

            $offset = $byte + strlen($word);
        }

        return new HtmlString($out.e(substr($text, $offset)));
    }

    /** How many letters at the start of this word of the text matched — 0 for none. */
    protected static function matched(string $word, array $query): int
    {
        $best = 0;
        $wordChars = mb_str_split($word);

        foreach ($query as $typed) {
            $typedChars = mb_str_split($typed);

            if (str_starts_with($word, $typed)) {
                $best = max($best, count($typedChars));

                continue;
            }

            // Another form: "ноутбуки" and "ноутбук" share "ноутбук" — marked
            // when the shared start is most of what was typed.
            $common = 0;

            while ($common < count($typedChars) && $common < count($wordChars) && $typedChars[$common] === $wordChars[$common]) {
                $common++;
            }

            if ($common >= 4 && $common >= 0.6 * count($typedChars)) {
                $best = max($best, $common);
            }
        }

        return $best;
    }
}
