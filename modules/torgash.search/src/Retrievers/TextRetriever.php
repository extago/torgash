<?php

namespace Torgash\Search\Retrievers;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Torgash\Search\Languages;
use Torgash\Search\Retriever;
use Torgash\Search\SearchQuery;

/**
 * The document match: every word of the query must be found, each word as the
 * START of a word in the document ("ноут" → "ноутбук") or as a stem in any of
 * the shop's languages ("ноутбуки" → "ноутбук").
 *
 * Scored by Postgres's cover density over the field weights, plus what a
 * tsvector cannot see and a person ranks first: the SKU exactly as typed, the
 * SKU starting with it, the name starting with it, a word of the name starting
 * with it.
 */
class TextRetriever implements Retriever
{
    public function retrieve(SearchQuery $query, Builder $listed, int $limit): array
    {
        if ($query->isEmpty()) {
            return [];
        }

        [$tsquery, $bindings] = $this->tsquery($query);
        $like = addcslashes($query->normalized, '\\%_');

        return DB::table('m_search_documents as d')
            ->selectRaw(
                "d.product_id, ts_rank_cd(d.document, {$tsquery}, 1)
                    + (case when d.sku = ? then 5 else 0 end)
                    + (case when d.sku like ? then 2 else 0 end)
                    + (case when d.title like ? then 1.5 else 0 end)
                    + (case when d.title like ? then 1 else 0 end) as score",
                [...$bindings, $query->normalized, $like.'%', $like.'%', '% '.$like.'%'],
            )
            ->whereRaw("d.document @@ {$tsquery}", $bindings)
            ->whereIn('d.product_id', $listed)
            ->orderByDesc('score')
            ->orderBy('d.product_id')
            ->limit($limit)
            ->pluck('score', 'product_id')
            ->map(fn ($score) => (float) $score)
            ->all();
    }

    /**
     * `(word:* | stem_ru(word) | stem_en(word)) & (…)` — built from words that
     * are letters and digits only (SearchQuery), so nothing a customer types
     * can be read as a tsquery operator.
     *
     * @return array{0: string, 1: list<string>}
     */
    protected function tsquery(SearchQuery $query): array
    {
        $stemmers = Languages::stemmers($this->locales());
        $words = [];
        $bindings = [];

        foreach ($query->words as $word) {
            $alternatives = ["to_tsquery('simple', ?)"];
            $bindings[] = $word.':*';

            foreach ($stemmers as $stemmer) {
                $alternatives[] = "plainto_tsquery('{$stemmer}', ?)";
                $bindings[] = $word;
            }

            $words[] = '('.implode(' || ', $alternatives).')';
        }

        return ['('.implode(' && ', $words).')', $bindings];
    }

    /** @return list<string> */
    protected function locales(): array
    {
        $fallback = [content_base_locale()];

        try {
            return DB::table('languages')->where('enabled', true)->pluck('code')->all() ?: $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
