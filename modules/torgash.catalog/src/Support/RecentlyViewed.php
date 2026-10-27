<?php

namespace Torgash\Catalog\Support;

/**
 * The products this visitor has looked at, newest first — what the "Viewed
 * recently" product source shows.
 *
 * Its own list beside the session's `viewed_products`, not a reading of it:
 * that one is a "counted already" guard for the popularity counters, kept in
 * the order products were FIRST seen, and a product looked at again an hour
 * later must come back to the front here while it must never be counted twice
 * there. Two questions, two lists.
 *
 * The session and nothing else: no table, no cookie of its own — what a
 * visitor browsed is not something the shop keeps after they leave. Switched
 * off by the `recently_viewed` setting, in which case nothing is remembered
 * and nothing is shown.
 */
class RecentlyViewed
{
    public const SESSION_KEY = 'catalog.recently_viewed';

    /** Kept at most — a block shows a dozen; the rest is room for the ones it skips (hidden, the current product). */
    public const KEEP = 30;

    public function enabled(): bool
    {
        return (bool) setting('torgash.catalog', 'recently_viewed', true);
    }

    public function remember(int $productId): void
    {
        if (! $this->enabled()) {
            return;
        }

        $ids = array_values(array_filter($this->ids(), fn (int $id) => $id !== $productId));
        array_unshift($ids, $productId);

        session([self::SESSION_KEY => array_slice($ids, 0, self::KEEP)]);
    }

    /** @return list<int> newest first */
    public function ids(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return array_values(array_map('intval', array_filter((array) session(self::SESSION_KEY, []), 'is_numeric')));
    }
}
