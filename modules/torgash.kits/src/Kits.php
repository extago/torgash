<?php

namespace Torgash\Kits;

use App\Core\Display\DisplayRules;
use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Product;
use Torgash\Kits\Models\Kit;

/**
 * Which kits a product is the MAIN thing of (PLAN §33.5).
 *
 * Two sources, the same shape the options module's groups have: a kit written
 * for this product, and a shared kit placed by core's display rules — "every
 * phone gets a glass and a case". A shared kit has no main product of its own,
 * so the product whose page it is drawn on becomes the main one, which is what
 * makes one row cover a category that grows.
 *
 * A registered per-request singleton: a product page asks once to draw the
 * card, and a cart with three lines asks once per line to price them.
 */
class Kits
{
    /** @var array<int, Collection<int, Kit>> */
    protected array $memo = [];

    /** @var Collection<int, Kit>|null */
    protected ?Collection $shared = null;

    /** @var Collection<int, Kit>|null */
    protected ?Collection $all = null;

    /**
     * Every kit this product is the main thing of, in the order they are shown.
     *
     * @return Collection<int, Kit>
     */
    public function for(Product $product): Collection
    {
        $id = (int) $product->id;

        if (isset($this->memo[$id])) {
            return $this->memo[$id];
        }

        $own = $id > 0
            ? $this->current()->filter(fn (Kit $kit) => (int) $kit->main_product_id === $id)
            : collect();

        $shared = $this->sharedKits()->filter(fn (Kit $kit) => app(DisplayRules::class)->passes(
            (array) $kit->rules,
            ['product' => $product],
            (string) $kit->rules_match,
        ));

        return $this->memo[$id] = $own->concat($shared)
            ->sortBy([['sort', 'asc'], ['id', 'asc']])
            ->values();
    }

    /** Whether this product leads a kit at all — what a page asks before drawing anything. */
    public function has(Product $product): bool
    {
        return $this->for($product)->isNotEmpty();
    }

    public function find(int $id): ?Kit
    {
        return $this->current()->firstWhere('id', $id);
    }

    /**
     * Every kit that is on offer right now — enabled and inside its dates.
     *
     * Read once per request: a cart line asks "which kit was this bought in",
     * and a card asks "which kits does this product lead", and both would
     * otherwise be a query each.
     *
     * @return Collection<int, Kit>
     */
    public function current(): Collection
    {
        return $this->all ??= Kit::with('slots')
            ->where('enabled', true)
            ->get()
            ->filter(fn (Kit $kit) => $kit->isCurrent())
            ->values();
    }

    /** @return Collection<int, Kit> */
    protected function sharedKits(): Collection
    {
        return $this->shared ??= $this->current()->filter(fn (Kit $kit) => $kit->isShared())->values();
    }

    /** For tests and for a screen that has just saved. */
    public function flush(): void
    {
        $this->memo = [];
        $this->shared = null;
        $this->all = null;
    }
}
