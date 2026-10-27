<?php

namespace Torgash\Search\Retrievers;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Torgash\Search\Retriever;
use Torgash\Search\Rules;
use Torgash\Search\SearchQuery;

/**
 * The products a shop's phrase puts first when the query IS that phrase,
 * in the order the shop gave. Registered with a weight no text match can
 * reach, so they lead whatever else is found — and they are found even when
 * their text does not mention the query, which is the point ("подарок маме").
 * Still only what this visitor may be shown.
 */
class PinnedRetriever implements Retriever
{
    public function retrieve(SearchQuery $query, Builder $listed, int $limit): array
    {
        $ids = app(Rules::class)->pinned($query);

        if ($ids === []) {
            return [];
        }

        $visible = DB::query()->fromSub($listed, 'l')->whereIn('l.id', $ids)->pluck('l.id')->map(fn ($id) => (int) $id)->flip();
        $scores = [];

        foreach (array_slice($ids, 0, $limit) as $position => $id) {
            if (isset($visible[$id])) {
                $scores[$id] = 1.0 - $position * 0.001;
            }
        }

        return $scores;
    }
}
