<?php

namespace Torgash\Search;

use Illuminate\Database\Query\Builder;

/**
 * A source of candidates: given a query, which products, and how well each
 * matches — on this retriever's OWN scale. The engine normalises every
 * source to its best answer before adding them up, so a retriever never has
 * to know how another one scores.
 *
 * Registered through `search.retrievers`, which is how a meaning-based source
 * (vector embeddings written by an LLM) joins later as a module of its own.
 * `$listed` is the subquery of product ids THIS visitor may be shown
 * (`Product::listed()`): a retriever narrows by it in SQL, so a limit is never
 * spent on products that would be thrown away.
 */
interface Retriever
{
    /**
     * @return array<int, float> product id => score, best first or not
     */
    public function retrieve(SearchQuery $query, Builder $listed, int $limit): array;
}
