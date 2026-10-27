<?php

namespace Torgash\Promotions;

use Illuminate\Support\Collection;
use Torgash\Promotions\Models\Promotion;

/**
 * Active promotions for a zone, read once per request.
 *
 * Product::cheapestPriceCandidate() consults this for EVERY product on a
 * page (via the two hooks it calls), so without this a grid of N products
 * would run N (really 2N — candidates() and adjust() each ask) queries to
 * learn "no promotions are running" — the exact N+1 QueryBudgetTest exists to
 * catch. A registered singleton, not a `static` property, for the same
 * reason as every other per-request memo here (Currencies, PriceTypes): a
 * static would outlive the request under a persistent worker. Flushed by
 * PromotionsServiceProvider on Promotion/PromotionTier save or delete —
 * same idiom as Currencies flushing on a rate change.
 */
class PromotionCache
{
    /** @var array<string, Collection<int, Promotion>> */
    protected array $byZone = [];

    /** @return Collection<int, Promotion> */
    public function activeInZone(string $zone): Collection
    {
        return $this->byZone[$zone] ??= Promotion::activeInZone($zone);
    }

    public function flush(): void
    {
        $this->byZone = [];
    }
}
