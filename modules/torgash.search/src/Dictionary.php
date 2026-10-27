<?php

namespace Torgash\Search;

use Illuminate\Support\Facades\DB;

/**
 * The words the catalogue is written in (`m_search_words`) — what a typo is
 * corrected TO.
 *
 * Filled by the `Indexer` as it writes documents: a document remembers the
 * words it contributed, so rewriting one product moves the counts by exactly
 * what changed (`apply()`), and a full rebuild recounts everything from the
 * documents at the end (`rebuild()`) — which is also what puts right a count
 * that drifted.
 */
class Dictionary
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 40;

    /**
     * The words of a text, as a query would be split into them — lower case,
     * letters and digits only, the SKU hyphen already a separator.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower(Indexer::separate($text)), $m);

        return array_values(array_unique(array_filter(
            $m[0],
            fn ($w) => ($n = mb_strlen($w)) >= self::MIN_LENGTH && $n <= self::MAX_LENGTH,
        )));
    }

    /**
     * One document went from the `$before` words to the `$after` ones.
     *
     * @param  array{words: list<string>, text_words: list<string>}  $before
     * @param  array{words: list<string>, text_words: list<string>}  $after
     */
    public function apply(array $before, array $after): void
    {
        foreach (['words' => 'products', 'text_words' => 'mentions'] as $key => $column) {
            $added = array_values(array_diff($after[$key], $before[$key]));
            $removed = array_values(array_diff($before[$key], $after[$key]));

            if ($added !== []) {
                DB::statement(
                    "insert into m_search_words (word, {$column})
                     select w, 1 from jsonb_array_elements_text(?::jsonb) w
                     on conflict (word) do update set {$column} = m_search_words.{$column} + 1",
                    [json_encode($added, JSON_UNESCAPED_UNICODE)],
                );
            }

            if ($removed !== []) {
                $json = json_encode($removed, JSON_UNESCAPED_UNICODE);
                DB::statement(
                    "update m_search_words set {$column} = greatest({$column} - 1, 0)
                     where word in (select jsonb_array_elements_text(?::jsonb))",
                    [$json],
                );
                DB::statement(
                    'delete from m_search_words where products = 0 and mentions = 0
                     and word in (select jsonb_array_elements_text(?::jsonb))',
                    [$json],
                );
            }
        }
    }

    /** Recount every word from the documents. */
    public function rebuild(): void
    {
        DB::transaction(function () {
            DB::statement('delete from m_search_words');
            DB::statement('
                insert into m_search_words (word, products, mentions)
                select w, sum(p), sum(m) from (
                    select jsonb_array_elements_text(words) w, 1 p, 0 m from m_search_documents
                    union all
                    select jsonb_array_elements_text(text_words) w, 0 p, 1 m from m_search_documents
                ) x
                group by w
            ');
        });
    }

    public function count(): int
    {
        return (int) DB::table('m_search_words')->count();
    }

    /**
     * Which of these words the catalogue has, and how: true for a word that
     * NAMES products (a name, SKU, brand, searched characteristic), false for
     * one only written in a text about them. `$prefix` (the last word, which
     * may still be being typed) also counts as the start of such a word.
     *
     * @param  list<string>  $words
     * @return array<string, bool>
     */
    public function known(array $words, ?string $prefix = null): array
    {
        if ($words === []) {
            return [];
        }

        $known = DB::table('m_search_words')->whereIn('word', $words)->pluck('products', 'word')
            ->map(fn ($products) => (int) $products > 0)->all();

        if ($prefix !== null && ! ($known[$prefix] ?? false)) {
            $starts = DB::table('m_search_words')->where('word', 'like', addcslashes($prefix, '\%_').'%')
                ->selectRaw('max(products) as products, count(*) as n')->first();

            if ((int) $starts->n > 0) {
                $known[$prefix] = ($known[$prefix] ?? false) || (int) $starts->products > 0;
            }
        }

        return $known;
    }

    /**
     * The words that look most like this one, letter-triple by letter-triple,
     * nearest first — candidates for `Speller` to measure properly.
     *
     * @return list<object{word: string, products: int, mentions: int}>
     */
    public function nearest(string $word, int $limit = 60): array
    {
        return DB::table('m_search_words')
            ->select(['word', 'products', 'mentions'])
            ->orderByRaw('word <-> ?', [$word])
            ->limit($limit)
            ->get()
            ->all();
    }
}
