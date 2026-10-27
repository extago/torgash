<?php

namespace Torgash\Checkout\Orders;

use Illuminate\Support\Collection;
use Torgash\Cart\Contracts\Basket;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\Measurements;
use Torgash\Checkout\Models\Order;

/**
 * A placed order, answering the questions a cart answers, so that re-quoting
 * its delivery in the admin runs through exactly the code that priced it at
 * checkout — same zones, same bands, same carrier, same conditions.
 *
 * Two things are deliberately NOT recomputed. The money comes from the order
 * (`subtotal`, `coupon_code`): what the customer is being charged is a fact
 * about the order, not something to re-derive from today's prices, and a
 * "free above 500" threshold has to measure that fact. The weight and volume,
 * on the other hand, ARE asked of the catalogue: they are properties of the
 * goods, the order never froze them, and the parcel that has to be shipped
 * weighs what it weighs.
 *
 * A line whose product has since been deleted is not listed — and that is what
 * makes weightIsKnown() false, which is the honest answer: nobody can say what
 * that parcel weighs any more.
 */
class OrderBasket implements Basket
{
    protected ?Collection $items = null;

    public function __construct(protected Order $order) {}

    public function items(): Collection
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $lines = collect($this->order->items ?? []);
        $products = Product::whereIn('id', $lines->pluck('product_id'))->withCardData()->get()->keyBy('id');

        // One query for the whole order, not one per line — the same reason
        // CartManager primes here.
        app(Measurements::class)->prime($products);

        return $this->items = $lines
            ->map(function (array $line) use ($products) {
                $product = $products->get((int) $line['product_id']);

                return $product === null ? null : (object) [
                    'product' => $product,
                    'qty' => (int) $line['qty'],
                    'unit_price' => (int) $line['unit_price'],
                    'line_total' => (int) $line['line_total'],
                ];
            })
            ->filter()
            ->values();
    }

    /** Every line's quantity, INCLUDING lines whose product is gone: they still ship. */
    public function count(): int
    {
        return array_sum(array_map(fn (array $line) => (int) $line['qty'], $this->order->items ?? []));
    }

    public function total(): int
    {
        return (int) $this->order->subtotal;
    }

    public function weight(): int
    {
        return $this->items()->sum(fn (object $item) => ($item->product->resolvedWeight() ?? 0) * $item->qty);
    }

    public function weightIsKnown(): bool
    {
        if ($this->missingProducts()) {
            return false;
        }

        return $this->items()->every(fn (object $item) => $item->product->resolvedWeight() !== null);
    }

    public function volume(): int
    {
        return (int) round($this->items()->sum(function (object $item) {
            $box = $item->product->resolvedBox();

            // Cubic millimetres to cubic centimetres: 10 × 10 × 10.
            return $box === null
                ? 0
                : $box['length'] * $box['width'] * $box['height'] / 1000 * $item->qty;
        }));
    }

    public function volumeIsKnown(): bool
    {
        if ($this->missingProducts()) {
            return false;
        }

        return $this->items()->every(fn (object $item) => $item->product->resolvedBox() !== null);
    }

    public function couponCode(): ?string
    {
        return $this->order->coupon_code;
    }

    /** The order lists lines the catalogue can no longer describe. */
    protected function missingProducts(): bool
    {
        return $this->items()->count() !== count($this->order->items ?? []);
    }

    public function needsDelivery(): bool
    {
        $items = $this->items();

        return $items->isEmpty() || $items->contains(fn ($item) => $item->product->needsDelivery());
    }
}
