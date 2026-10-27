<?php

namespace Torgash\Search;

/**
 * What a search found: product ids in the order they are shown, and — when
 * the query had to be read differently to find anything — how it was read,
 * so the page can say "showing results for «ноутбук»" instead of silently
 * answering a different question.
 *
 * `how`: null (as typed), `layout` (the other keyboard), `typo` (unknown
 * words corrected by the catalogue's dictionary), `fallback` (a fallback
 * source a module registered). `didYouMean`: found as typed, but a corrected
 * query would find more — offered, not applied.
 */
final class SearchResult
{
    /**
     * @param  list<int>  $ids
     */
    public function __construct(
        public readonly SearchQuery $query,
        public readonly array $ids,
        public readonly ?string $readAs = null,
        public readonly ?string $how = null,
        public readonly ?string $didYouMean = null,
    ) {}

    public function total(): int
    {
        return count($this->ids);
    }
}
