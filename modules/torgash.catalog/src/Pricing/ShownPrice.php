<?php

namespace Torgash\Catalog\Pricing;

use Torgash\Catalog\Models\Product;

/**
 * What a storefront actually PRINTS for a product, and whether that number is
 * the price or only the cheapest one (PLAN §33.7).
 *
 * Two facts, and they have to travel together. A product that cannot be bought
 * without answering something has no price: its own number is a sum nobody is
 * able to pay, and printing it plain on a card promises a purchase the buy
 * button will refuse. So the catalogue asks two questions in one place —
 *
 *   `catalog.price.from`     how little can this actually be bought for
 *   `catalog.price.display`  what does that number render as (tax)
 *
 * — in that order, because a tax is charged on whatever is being bought and
 * therefore applies to the minimum, not to a price that is never paid. Both
 * are seeded with the plain price, so a shop with neither module gets exactly
 * `effectivePrice()` back and this class costs it nothing.
 *
 * The catalogue names no module here. It states what must be true of a price
 * it prints; `torgash.options` is what knows a server needs a processor, and
 * a module that invents another reason a price is a floor answers the same
 * filter without this class hearing of it.
 *
 * One place, because the card, the product page, the feed and the JSON-LD all
 * print this and a shop whose feed says 45 000 and whose card says 30 000 is
 * penalised for the disagreement rather than for either number.
 *
 * `publicMinimum()` is the same answer for NOBODY in particular — an export
 * has no visitor, and the manager pressing the button is not one
 * (`ProductFacts` keeps the same discipline for the same reason).
 */
class ShownPrice
{
    /**
     * The least this product can be bought for, in the shop's books, measured
     * against a price that is already the visitor's own.
     */
    public function minimum(Product $product, ?int $base = null): int
    {
        $base ??= (int) $product->effectivePrice();

        return (int) hook_filter('catalog.price.from', $base, [
            'product' => $product,
            'base' => $base,
        ]);
    }

    /** The same, for an answer with no visitor behind it. */
    public function publicMinimum(Product $product): int
    {
        return $this->minimum($product, (int) $product->publicPrice());
    }

    /**
     * Is the number we are printing a floor rather than a price — i.e. does
     * this product demand something that costs money before it can be bought?
     */
    public function isFrom(Product $product, ?int $base = null): bool
    {
        $base ??= (int) $product->effectivePrice();

        return $this->minimum($product, $base) > $base;
    }

    /** The number to print, taxed as the shop asked for it to be shown. */
    public function amount(Product $product, ?int $base = null): int
    {
        return (int) hook_filter('catalog.price.display', $this->minimum($product, $base), [
            'product' => $product,
        ]);
    }

    /**
     * The number to print, in words — "from 45 000 ₴" where it is a floor.
     *
     * The word lives here rather than in each template for the same reason the
     * number does: three templates saying it three ways is three chances for
     * one of them to say it plainly and quote a price that cannot be paid.
     */
    public function format(Product $product, ?int $base = null): string
    {
        $price = format_price($this->amount($product, $base));

        return $this->isFrom($product, $base)
            ? __('from :price', ['price' => $price])
            : $price;
    }
}
