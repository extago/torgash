<?php

namespace Torgash\Search;

/**
 * Corrects the words of a query the catalogue does not know into the nearest
 * words it does (`Dictionary`), without any outside service — a customer's
 * query goes nowhere, and a shop's SKUs and brands are in no general
 * dictionary anyway.
 *
 * Two steps, because neither alone is right. Trigrams FIND candidates quickly
 * (the index answers "the words that look most like this one"), but they
 * cannot tell a typo from a false friend: measured on this catalogue, real
 * typos scored 0.43–0.71 and false friends 0.33–0.50 ("айфон ~ смартфон" =
 * 0.5 = "адапетр ~ адаптер"). The EDIT DISTANCE can — two letters swapped is
 * one edit, "айфон" is five away from "смартфон" — so the candidates are
 * measured by it and only a close one is accepted, closer still for a short
 * word, where one letter is a larger share of it.
 *
 * A word only written ABOUT products (in a description, never in a name) is
 * in the dictionary too, and may itself be the typo — a description says
 * "smarphone" as easily as a customer does. It is corrected only towards a
 * word that names products; a word that names products is never corrected.
 *
 * The last word may still be being typed, so it is also compared with the
 * START of each candidate ("нотб" is one letter away from "ноутб…") and, if
 * corrected, becomes the whole word.
 *
 * Numbers are never corrected: "2001" and "2002" are two models, not a typo.
 */
class Speller
{
    public function __construct(protected Dictionary $dictionary) {}

    /**
     * The query with every unknown word corrected, or null when nothing needed
     * or could be corrected.
     */
    public function correct(SearchQuery $query): ?string
    {
        $words = $query->words;

        if ($words === []) {
            return null;
        }

        $last = $words[count($words) - 1];
        // A synonym's word is the shop's own vocabulary: "мобила" means
        // "телефон" by rule, and correcting it would undo the rule.
        $known = $this->dictionary->known($words, $last) + array_map(fn () => true, app(Rules::class)->synonymWords());
        $changed = false;

        foreach ($words as $i => $word) {
            if (($known[$word] ?? false) || ! $this->correctable($word)) {
                continue;
            }

            $better = $this->best($word, prefix: $word === $last, namingOnly: isset($known[$word]));

            if ($better !== null) {
                $words[$i] = $better;
                $changed = true;
            }
        }

        return $changed ? implode(' ', $words) : null;
    }

    /** How many edits a word of this length may be away and still be a typo of it. */
    public static function allowed(int $length): int
    {
        return match (true) {
            $length < 4 => 0,
            $length <= 5 => 1,
            $length <= 9 => 2,
            default => 3,
        };
    }

    protected function correctable(string $word): bool
    {
        return self::allowed(mb_strlen($word)) > 0 && preg_match('/\p{L}/u', $word) === 1;
    }

    /**
     * The nearest known word within reach: fewest edits, then a word that
     * NAMES products over one that is only written about them, then a word
     * starting with the same letter (a first letter is the one least often
     * mistyped), then the commoner, then the shorter.
     */
    protected function best(string $word, bool $prefix, bool $namingOnly = false): ?string
    {
        $chars = mb_str_split($word);
        $length = count($chars);
        $allowed = self::allowed($length);
        // Against a word's START one edit fewer: a prefix is shorter than the
        // word it will become, and "some beginning, two letters off" matches
        // half the dictionary.
        $allowedAsPrefix = max(1, $allowed - 1);
        $best = null;
        $bestKey = null;

        foreach ($this->dictionary->nearest($word) as $candidate) {
            $target = mb_str_split($candidate->word);

            if (preg_match('/\p{L}/u', $candidate->word) !== 1 || ($namingOnly && (int) $candidate->products === 0)) {
                continue;
            }

            $distance = self::distance($chars, $target);

            if ($distance > $allowed && $prefix && count($target) > $length) {
                foreach ([$length - 1, $length, $length + 1] as $cut) {
                    if ($cut > 0 && $cut <= count($target)) {
                        $asPrefix = self::distance($chars, array_slice($target, 0, $cut));
                        $distance = $asPrefix <= $allowedAsPrefix ? min($distance, $asPrefix) : $distance;
                    }
                }
            }

            if ($distance === 0 || $distance > $allowed) {
                continue;
            }

            $key = [
                $distance,
                $candidate->products > 0 ? 0 : 1,
                $target[0] === $chars[0] ? 0 : 1,
                -((int) $candidate->products + (int) $candidate->mentions),
                count($target),
                $candidate->word,
            ];

            if ($bestKey === null || $key < $bestKey) {
                $best = $candidate->word;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * Edits from one word to the other — a letter added, dropped, replaced,
     * or two neighbours swapped ("lapotp" is ONE edit from "laptop"), over
     * letters rather than bytes (PHP's `levenshtein()` counts a Cyrillic
     * letter as two).
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function distance(array $a, array $b): int
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 || $m === 0) {
            return max($n, $m);
        }

        $d = [];

        for ($i = 0; $i <= $n; $i++) {
            $d[$i][0] = $i;
        }

        for ($j = 0; $j <= $m; $j++) {
            $d[0][$j] = $j;
        }

        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);

                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$n][$m];
    }
}
