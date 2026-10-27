<?php

namespace Torgash\Checkout\Shipping;

use Torgash\Cart\Contracts\Basket;

/**
 * What is being shipped, in the only three numbers a rate is priced on:
 * the order total, the weight, and how many items there are.
 *
 * Other engines key this C / W / I; the names are kept
 * because the axes on a rate row use the same letters, and a shop owner who
 * has seen one will recognise the other.
 */
class Package
{
    public const AXES = ['C', 'W', 'I'];

    public function __construct(
        /** Order total in minor units, after any discount. */
        public readonly int $subtotal = 0,
        /** Total weight in grams. */
        public readonly int $weight = 0,
        /** Number of items, quantities counted. */
        public readonly int $items = 0,
        /**
         * Whether that weight is the whole truth. False when some line has no
         * weight anywhere — the column, the mapped characteristic, nothing —
         * and the sum above is therefore an understatement rather than a
         * measurement. A method priced by weight reads this before charging.
         */
        public readonly bool $weightKnown = true,
        /**
         * How much space it takes, in cubic centimetres — the goods' own volume,
         * summed. What a carrier bills on is that volume divided by its divisor
         * (see ShippingMethod::billableWeight), which is why the raw number is
         * carried here rather than a weight already converted by somebody's
         * assumptions.
         */
        public readonly int $volume = 0,
        /** False when some line has no measured box, exactly as weightKnown. */
        public readonly bool $volumeKnown = true,
    ) {}

    public static function forCart(Basket $cart, ?int $subtotal = null): self
    {
        return new self(
            subtotal: $subtotal ?? $cart->total(),
            weight: $cart->weight(),
            items: $cart->count(),
            weightKnown: $cart->weightIsKnown(),
            volume: $cart->volume(),
            volumeKnown: $cart->volumeIsKnown(),
        );
    }

    public function amount(string $axis): int
    {
        return match ($axis) {
            'C' => $this->subtotal,
            'W' => $this->weight,
            'I' => $this->items,
            default => 0,
        };
    }
}
