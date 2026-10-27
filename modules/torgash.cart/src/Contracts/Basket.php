<?php

namespace Torgash\Cart\Contracts;

use Illuminate\Support\Collection;

/**
 * Everything the delivery machinery needs to know about "the goods being
 * shipped" — asked of a cart, or of a placed order.
 *
 * It exists because pricing a delivery for an order in the admin is the SAME
 * question as pricing one at checkout: same zones, same rate bands, same
 * carrier, same conditions. The alternative was a second set of forOrder()
 * constructors down the whole stack (Package, CartFacts, the catalogue's
 * shipping.cart_lines listener), which is exactly the duplicated arithmetic
 * that eventually quotes one price and charges another.
 *
 * Implemented by Torgash\Cart\CartManager (the live cart) and by
 * Torgash\Checkout\Orders\OrderBasket (an order's frozen lines).
 */
interface Basket
{
    /**
     * The lines, each an object with `product`, `qty`, `unit_price` and
     * `line_total`. A line whose product no longer exists is not listed.
     *
     * @return Collection<int, object>
     */
    public function items(): Collection;

    /** Number of units, quantities counted. */
    public function count(): int;

    /** What the goods cost, in minor units, before any coupon. */
    public function total(): int;

    /** Total weight in grams; lines nobody measured count as nothing. */
    public function weight(): int;

    /** False when some line has no weight anywhere — see weight(). */
    public function weightIsKnown(): bool;

    /** The goods' own volume in cubic centimetres. */
    public function volume(): int;

    /** False when some line has no measured box. */
    public function volumeIsKnown(): bool;

    public function couponCode(): ?string;

    /**
     * Does anything here have to be sent? False for a basket of services,
     * downloads, electronic gift cards (PLAN §40.10/2) — and then there is no
     * delivery to quote and no address to ask for.
     */
    public function needsDelivery(): bool;
}
