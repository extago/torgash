<?php

namespace Torgash\Search\Retrievers;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Torgash\Search\Retriever;
use Torgash\Search\SearchQuery;
use Torgash\Search\Stats;

/**
 * What people chose for THIS query lifts it for the next person who asks it
 * ("чехол" → the case that is actually bought, not the first alphabetically).
 * Counted per query and product (Stats); scored by the logarithm of the
 * clicks, so a hundred clicks is a clear lead and not a hundred times one.
 * Weighted to reorder among real matches, never to outrank a far better one.
 */
class ClicksRetriever implements Retriever
{
    public function retrieve(SearchQuery $query, Builder $listed, int $limit): array
    {
        if (! Stats::enabled() || ! setting('torgash.search', 'click_boost')) {
            return [];
        }

        $clicks = app(Stats::class)->clicksFor($query);

        if ($clicks === []) {
            return [];
        }

        $visible = DB::query()->fromSub($listed, 'l')->whereIn('l.id', array_keys($clicks))->pluck('l.id')->map(fn ($id) => (int) $id)->flip();
        $scores = [];

        foreach ($clicks as $id => $n) {
            if (isset($visible[$id])) {
                $scores[$id] = log(1 + $n);
            }
        }

        arsort($scores);

        return array_slice($scores, 0, $limit, true);
    }
}
