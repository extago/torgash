<?php

namespace Torgash\Search;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Search\Models\Exclusion;
use Torgash\Search\Models\Phrase;
use Torgash\Search\Models\Synonym;

/**
 * The shop's own hand on its search — synonyms, exclusions, pinned phrases
 * (the three tables of the rules migration) — read once per request and
 * applied here, the one place that knows what they mean:
 *
 *  - `readings()`: the query as typed, plus each synonym's rewrite of it
 *    ("мобила samsung" → "телефон samsung"). The engine runs every reading
 *    and keeps each product's best score, a rewrite counting a little less
 *    than what was typed; an `instead` synonym drops the typed reading. A
 *    synonym matches whole words, in sequence.
 *  - `excluded()`: of these products, which a rule forbids for this query —
 *    a trigger is the START of a word ("чехл" is чехлы, чехла, чехлов), the
 *    target a word starting a word of the NAME, or a category with
 *    everything under it (a category IS its subcategories).
 *  - `pinned()`: the products a phrase puts first when it is exactly what was
 *    searched; `suggest()`: the phrases to offer while typing.
 *
 * What fired is written down (`fired`) for the admin's "try a query" — a rule
 * nobody can see working is a rule nobody trusts. A per-request singleton.
 */
class Rules
{
    /** How much a synonym's rewrite counts against what was typed. */
    public const REWRITE = 0.85;

    /** @var list<string> what fired, in words, for the admin */
    public array $fired = [];

    protected ?Collection $synonyms = null;

    protected ?Collection $exclusions = null;

    protected ?Collection $phrases = null;

    /**
     * Admin input — one entry per line or comma — as the words a query is
     * made of.
     *
     * @return list<string>
     */
    public static function entries(string|array|null $input): array
    {
        $lines = is_array($input) ? $input : preg_split('/[\r\n,;]+/u', (string) $input);
        $out = [];

        foreach ($lines as $line) {
            $words = (new SearchQuery((string) $line))->words;

            if ($words !== []) {
                $out[] = implode(' ', $words);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<array{0: SearchQuery, 1: float}>
     */
    public function readings(SearchQuery $query): array
    {
        $typed = true;
        $readings = [];
        $words = $query->words;

        foreach ($this->synonyms() as $synonym) {
            $target = (new SearchQuery((string) $synonym->target))->words;

            foreach ((array) $synonym->phrases as $phrase) {
                $from = explode(' ', (string) $phrase);
                $at = self::find($words, $from);

                if ($at === null || $target === []) {
                    continue;
                }

                $rewritten = implode(' ', [...array_slice($words, 0, $at), ...$target, ...array_slice($words, $at + count($from))]);
                $readings[$rewritten] ??= [new SearchQuery($rewritten), $synonym->mode === 'instead' ? 1.0 : self::REWRITE];
                $typed = $typed && $synonym->mode !== 'instead';
                $this->fired[] = __('Synonym: «:from» → «:to»', ['from' => $phrase, 'to' => implode(' ', $target)])
                    .($synonym->mode === 'instead' ? ' ('.__('instead').')' : '');

                break;
            }
        }

        return array_values([...($typed ? [[$query, 1.0]] : []), ...array_slice($readings, 0, 5)]);
    }

    /**
     * Words a synonym turns into something else — known to the speller, so
     * "мобила" is never corrected into a word of the catalogue.
     *
     * @return array<string, true>
     */
    public function synonymWords(): array
    {
        $out = [];

        foreach ($this->synonyms() as $synonym) {
            foreach ((array) $synonym->phrases as $phrase) {
                foreach (explode(' ', (string) $phrase) as $word) {
                    $out[$word] = true;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true> the ids a rule forbids for this query
     */
    public function excluded(SearchQuery $query, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $words = [];
        $categories = [];

        foreach ($this->exclusions() as $rule) {
            if (! $this->triggers($query->words, (array) $rule->when_words)) {
                continue;
            }

            $words = [...$words, ...array_filter((array) $rule->exclude_words)];

            if ($rule->exclude_category_id) {
                $categories[] = (int) $rule->exclude_category_id;
            }

            $this->fired[] = __('Rule: «:when» → not :what', [
                'when' => implode(', ', (array) $rule->when_words),
                'what' => $rule->note ?: implode(', ', array_filter([
                    implode(', ', (array) $rule->exclude_words),
                    $rule->exclude_category_id ? __('category #:id', ['id' => $rule->exclude_category_id]) : null,
                ])),
            ]);
        }

        $out = [];

        if ($words !== []) {
            // Words are letters and digits only (entries()), so the pattern
            // is data: \m is "the start of a word".
            $pattern = '\m('.implode('|', array_unique($words)).')';

            foreach (DB::table('m_search_documents')->whereIn('product_id', $ids)->whereRaw('title ~ ?', [$pattern])->pluck('product_id') as $id) {
                $out[(int) $id] = true;
            }
        }

        if ($categories !== []) {
            $tree = app(CategoryTree::class);
            $all = collect($categories)->flatMap(fn (int $id) => $tree->descendants($id))->unique()->all();

            foreach (DB::table('m_catalog_category_product')->whereIn('category_id', $all)->whereIn('product_id', $ids)->pluck('product_id') as $id) {
                $out[(int) $id] = true;
            }
        }

        return $out;
    }

    /** @return list<int> the products a phrase puts first, in its order */
    public function pinned(SearchQuery $query): array
    {
        $phrase = $this->phrases()->firstWhere('phrase', implode(' ', $query->words));

        if (! $phrase || empty($phrase->product_ids)) {
            return [];
        }

        $this->fired[] = __('Phrase «:phrase» puts :count products first', ['phrase' => $phrase->phrase, 'count' => count($phrase->product_ids)]);

        return array_values(array_map('intval', (array) $phrase->product_ids));
    }

    /**
     * Phrases to offer while typing: those starting with what was typed, or
     * with a word of theirs starting with it — the shop's priority first.
     *
     * @return list<string>
     */
    public function suggest(SearchQuery $query, int $limit = 5): array
    {
        $typed = implode(' ', $query->words);

        if ($typed === '') {
            return [];
        }

        return $this->phrases()
            ->filter(fn (Phrase $p) => $p->phrase !== $typed && (str_starts_with($p->phrase, $typed) || str_contains($p->phrase, ' '.$typed)))
            ->sortBy([['priority', 'desc'], ['phrase', 'asc']])
            ->take($limit)
            ->pluck('phrase')->values()->all();
    }

    /** @param  list<string>  $words @param  list<string>  $triggers */
    protected function triggers(array $words, array $triggers): bool
    {
        foreach ($triggers as $trigger) {
            foreach ($words as $word) {
                if ($trigger !== '' && str_starts_with($word, $trigger)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Where `$needle` sits in `$haystack` as a run of whole words, or null. */
    protected static function find(array $haystack, array $needle): ?int
    {
        $n = count($needle);

        for ($i = 0; $i + $n <= count($haystack); $i++) {
            if (array_slice($haystack, $i, $n) === $needle) {
                return $i;
            }
        }

        return null;
    }

    protected function synonyms(): Collection
    {
        return $this->synonyms ??= Synonym::where('enabled', true)->orderBy('id')->get();
    }

    protected function exclusions(): Collection
    {
        return $this->exclusions ??= Exclusion::where('enabled', true)->orderBy('id')->get();
    }

    protected function phrases(): Collection
    {
        return $this->phrases ??= Phrase::where('enabled', true)->get();
    }
}
