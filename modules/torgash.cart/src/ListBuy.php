<?php

namespace Torgash\Cart;

use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;

/**
 * Buying from a LIST — a catalogue card, the search window — as against the
 * product page. Two questions, both asked per product and both answered for
 * a whole page at once (primed off `catalog.products.listed`), because a grid
 * of twelve asking twelve times is the N+1 this engine refuses:
 *
 *  - does this product have to be CHOSEN before it is bought? Either it asks
 *    something (a size, a warranty — whoever asks answers `cart.product.asks`,
 *    this module names none of them) or it is one of a set of variations,
 *    which are separate products the customer picks between. Then the list
 *    shows a button with a gear, which opens the choice in a dialog
 *    (`CartController::choose`), and never drops a default into the basket;
 *  - is it already in the basket AS ITSELF — the line whose key is the
 *    product id (Lines)? A configured line is a different thing: "in cart"
 *    beside a phone is untrue when what is there is the phone with a
 *    warranty the button did not add.
 *
 * A per-request singleton (the rule for memos: never a static).
 */
class ListBuy
{
    /** @var array<string, int> variation_group => visible members */
    protected array $groups = [];

    /** @var array<int, int>|null */
    protected ?array $inCart = null;

    /** @param  iterable<Product>  $products */
    public function prime(iterable $products): void
    {
        $wanted = collect($products)
            ->map(fn ($p) => $p->variation_group ?? null)
            ->filter(fn ($g) => $g !== null && $g !== '' && ! isset($this->groups[$g]))
            ->unique()->values()->all();

        if ($wanted === []) {
            return;
        }

        $counts = Product::query()->visible()
            ->whereIn('variation_group', $wanted)
            ->groupBy('variation_group')
            ->selectRaw('variation_group, count(*) as n')
            ->pluck('n', 'variation_group');

        foreach ($wanted as $group) {
            $this->groups[$group] = (int) ($counts[$group] ?? 0);
        }
    }

    /**
     * Must the customer choose something before this can be bought?
     * `$variationChosen`: the caller lists one variation as itself (the table
     * of a group's variants), so the variation is not a question any more —
     * only what a module asks still is.
     */
    public function mustChoose(Product $product, bool $variationChosen = false): bool
    {
        return (! $variationChosen && $this->hasVariations($product))
            || (bool) hook_filter('cart.product.asks', false, ['product' => $product]);
    }

    public function hasVariations(Product $product): bool
    {
        if (! $product->variation_group) {
            return false;
        }

        $this->prime([$product]);

        return ($this->groups[$product->variation_group] ?? 0) > 1;
    }

    /** How many of this product the basket holds as itself — 0 for none. */
    public function inCart(Product|int $product): int
    {
        return $this->inCartMap()[is_int($product) ? $product : $product->id] ?? 0;
    }

    /**
     * A page being kept ready for every guest is drawn with nothing in the
     * basket — and without reading it; the visitor's own ticks are put on
     * by a personal part (in-cart-marks).
     *
     * @return array<int, int> product id => quantity
     */
    public function inCartMap(): array
    {
        if (page_cache()->drawing()) {
            return [];
        }

        if ($this->inCart === null) {
            $this->inCart = [];

            foreach (app(CartManager::class)->raw() as $line) {
                if ((string) $line['key'] === (string) $line['product_id']) {
                    $this->inCart[(int) $line['product_id']] = (int) $line['qty'];
                }
            }
        }

        return $this->inCart;
    }

    /** The basket changed within this request (an add answered with a fragment). */
    public function forgetCart(): void
    {
        $this->inCart = null;
    }
}
