<?php

namespace Torgash\Catalog\Features;

use Illuminate\Support\Collection;
use Torgash\Catalog\Models\FeatureValue;

/**
 * When two words are one value written two ways.
 *
 * The first live shop had "тёмно‑синий" — a ё and a NON-BREAKING hyphen —
 * beside "темно-синий", each with its own products, its own tick in the filter
 * and its own filter page, and no screen said they were the same, because to a
 * computer they are not. A supplier's file brings the second spelling, an
 * import that compares exact words makes it a second value, and the shop
 * finds out from a customer who ticked one and missed half the products.
 *
 * The rule: case does not matter, ё is е, every dash and hyphen is "-", every
 * kind of space is one space, curly quotes and primes are straight, invisible
 * characters are not there, and a letter written as two code points is the
 * letter. Nothing more — "1,5" and "1.5", "золотой" and "золотистый" are a
 * person's decision (merging, PLAN §60), not a spelling.
 *
 * Asked in PHP (`key()`) and in SQL (`sql()`): an import looks a value up in
 * the database, a screen groups the values it already holds. Both are built
 * from the ONE table below, and MergeFeatureValuesTest holds them to the same
 * answers — two copies of a rule like this one quietly stop agreeing.
 */
final class Spelling
{
    /** A character => what it is read as. Lower case only: both sides lower-case first. */
    protected const SAME = [
        'ё' => 'е',

        // Hyphens and dashes, the non-breaking hyphen (U+2011) first among them.
        "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-', "\u{2014}" => '-',
        "\u{2015}" => '-', "\u{2212}" => '-', "\u{FE58}" => '-', "\u{FE63}" => '-', "\u{FF0D}" => '-',

        // Spaces: control ones, the non-breaking one, the typographic ones.
        "\t" => ' ', "\n" => ' ', "\r" => ' ', "\u{000B}" => ' ', "\u{000C}" => ' ', "\u{00A0}" => ' ',
        "\u{2000}" => ' ', "\u{2001}" => ' ', "\u{2002}" => ' ', "\u{2003}" => ' ', "\u{2004}" => ' ',
        "\u{2005}" => ' ', "\u{2006}" => ' ', "\u{2007}" => ' ', "\u{2008}" => ' ', "\u{2009}" => ' ',
        "\u{200A}" => ' ', "\u{202F}" => ' ', "\u{205F}" => ' ', "\u{3000}" => ' ',

        // Quotes: «», curly ones, the double prime of 6.7″.
        "\u{00AB}" => '"', "\u{00BB}" => '"', "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"',
        "\u{201F}" => '"', "\u{2033}" => '"', "\u{FF02}" => '"',

        // Apostrophes: Levi’s, the prime of 5′.
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'", "\u{2032}" => "'",
        "\u{FF07}" => "'",
    ];

    /** Characters that are not there at all: a soft hyphen, the zero-width ones, a byte-order mark. */
    protected const NOTHING = ["\u{00AD}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{2060}", "\u{FEFF}"];

    /** What a word is compared by — "Тёмно‑синий " and "темно-синий" give the same key. */
    public static function key(string $text): string
    {
        // Composed first: "ё" can arrive as "е" plus a combining diaeresis
        // (a file saved on a Mac), and only the composed one is in the table.
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }

        $text = strtr(mb_strtolower($text), self::SAME + array_fill_keys(self::NOTHING, ''));

        return trim((string) preg_replace('/ {2,}/', ' ', $text), ' ');
    }

    /**
     * The same key in SQL, computed from `$expression` (a column, a jsonb
     * text) — with the bindings it needs, which come AFTER any the expression
     * itself has. `translate()` replaces character for character and drops the
     * ones it has no replacement for, which is exactly SAME followed by
     * NOTHING.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function sql(string $expression): array
    {
        return [
            "btrim(regexp_replace(translate(lower(normalize({$expression}, NFC)), ?, ?), ' {2,}', ' ', 'g'), ' ')",
            [implode('', array_keys(self::SAME)).implode('', self::NOTHING), implode('', self::SAME)],
        ];
    }

    /**
     * The value of a characteristic written like `$text`, asked in three
     * steps: its own spelling (case aside), then a value written alike, then
     * a spelling merged into a value. In that order because a shop may still
     * have twins nobody has merged — "темно-синий" from a file belongs to the
     * value spelled so, not to the older "тёмно‑синий" — and a real value
     * always wins over another value's remembered spelling, so a shop that
     * makes "золотистый" a value of its own again gets it from the next import.
     */
    public static function find(int $featureId, string $text): ?FeatureValue
    {
        $text = trim($text);
        $key = self::key($text);

        if ($key === '') {
            return null;
        }

        [$sql, $bindings] = self::sql('n.value');
        $values = fn () => FeatureValue::query()->where('feature_id', $featureId)->orderBy('id');

        return $values()
            ->whereRaw('exists (select 1 from jsonb_each_text(value) n where lower(n.value) = lower(?))', [$text])
            ->first()
            ?? $values()
                ->whereRaw("exists (select 1 from jsonb_each_text(value) n where {$sql} = ?)", [...$bindings, $key])
                ->first()
            ?? $values()
                ->whereRaw(
                    "exists (select 1 from jsonb_array_elements(coalesce(aliases, '[]'::jsonb)) a
                        cross join jsonb_each_text(case when jsonb_typeof(a->'names') = 'object' then a->'names' else '{}'::jsonb end) n
                        where {$sql} = ?)",
                    [...$bindings, $key],
                )
                ->first();
    }

    /**
     * Values written alike, as groups of two or more — what the merge window
     * offers in one click and the invariant reports. Linked through ANY
     * language: "Dark blue"/"тёмно‑синий" and "dark blue"/"синий" are one
     * group, because the English says so.
     *
     * @param  iterable<FeatureValue>  $values
     * @return list<list<FeatureValue>> each group in the order the values came
     */
    public static function groups(iterable $values): array
    {
        $values = Collection::make($values)->values();
        $parent = range(0, max(0, $values->count() - 1));
        $find = function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $i = $parent[$i] = $parent[$parent[$i]];
            }

            return $i;
        };

        $owner = [];
        foreach ($values as $i => $value) {
            foreach ((array) $value->value as $text) {
                $key = is_string($text) ? self::key($text) : '';

                if ($key === '') {
                    continue;
                }

                if (isset($owner[$key])) {
                    $parent[$find($i)] = $find($owner[$key]);
                } else {
                    $owner[$key] = $i;
                }
            }
        }

        $groups = [];
        foreach ($values as $i => $value) {
            $groups[$find($i)][] = $value;
        }

        return array_values(array_filter($groups, fn (array $group) => count($group) > 1));
    }
}
