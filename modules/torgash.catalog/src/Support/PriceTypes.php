<?php

namespace Torgash\Catalog\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Torgash\Catalog\Models\PriceType;

/**
 * The non-base price types with their customer groups, read once per request.
 *
 * Product::effectivePrice() consults them for every product on the page, and
 * most shops have none at all — so this exists to keep a grid of N products
 * from running N queries to learn that.
 *
 * A registered singleton and not the `static` property this used to be on
 * Product: a static memo survives the request under a long-lived worker, which
 * would serve a stale price table until the worker recycled. Same reasoning as
 * App\Core\Support\Currencies.
 */
class PriceTypes
{
    /** @var Collection<int, PriceType>|null */
    protected ?Collection $all = null;

    /** @var Collection<int, PriceType>|null */
    protected ?Collection $shown = null;

    /** Every enabled, non-base price type $user may see. @return Collection<int, PriceType> */
    public function visibleTo(?User $user): Collection
    {
        $this->all ??= PriceType::where('is_base', false)->where('enabled', true)
            ->with('customerGroups')->get();

        return $this->all->filter(fn (PriceType $type) => $type->visibleTo($user));
    }

    /**
     * The types a product page names beside the price ("club price 900"), in
     * their own order. Asked once per placeholder on the page — measured: four
     * identical queries on one product page before it was remembered here.
     *
     * @return Collection<int, PriceType>
     */
    public function shownOnStorefront(): Collection
    {
        return $this->shown ??= PriceType::where('show_on_storefront', true)->where('enabled', true)->orderBy('sort')->get();
    }

    /** For the admin, which edits price types and then reads them back. */
    public function flush(): void
    {
        $this->all = null;
        $this->shown = null;
    }
}
