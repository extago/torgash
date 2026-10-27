<?php

namespace Torgash\Checkout\Shipping;

use Illuminate\Support\Collection;
use Torgash\Cart\Contracts\Basket;
use Torgash\Catalog\Models\Product;

/**
 * One product, one piece — the basket a product page asks delivery about
 * ("what would it cost to send THIS to my town").
 *
 * A Basket like the live cart and an order's lines, so the estimate goes
 * through exactly the path the cart's does — the same zone, the same
 * conditions, the same weight and box — and the product page can never quote
 * a delivery the cart would then price differently. No coupon: a code typed
 * into the cart is about that cart, not about a product being looked at.
 */
class ProductBasket implements Basket
{
    public function __construct(protected Product $product) {}

    public function items(): Collection
    {
        $price = $this->product->effectivePrice();

        return collect([(object) [
            'product' => $this->product,
            'qty' => 1,
            'unit_price' => $price,
            'line_total' => $price,
        ]]);
    }

    public function count(): int
    {
        return 1;
    }

    public function total(): int
    {
        return $this->product->effectivePrice();
    }

    public function weight(): int
    {
        return (int) ($this->product->resolvedWeight() ?? 0);
    }

    public function weightIsKnown(): bool
    {
        return $this->product->resolvedWeight() !== null;
    }

    public function volume(): int
    {
        $box = $this->product->resolvedBox();

        // Cubic millimetres to cubic centimetres: 10 × 10 × 10.
        return $box === null ? 0 : (int) round($box['length'] * $box['width'] * $box['height'] / 1000);
    }

    public function volumeIsKnown(): bool
    {
        return $this->product->resolvedBox() !== null;
    }

    public function couponCode(): ?string
    {
        return null;
    }

    public function needsDelivery(): bool
    {
        return $this->product->needsDelivery();
    }
}
