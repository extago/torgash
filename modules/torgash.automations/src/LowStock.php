<?php

namespace Torgash\Automations;

use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;

/**
 * What an order leaves on the shelf — asked when the order happens, not by
 * watching every stock movement: a sale is what empties a shelf, and the
 * message about the sale is where "only 1 left" belongs.
 *
 * Per product, as it stands when the rule runs, AFTER this order: an order
 * that has not taken its units yet (awaiting payment) is counted as if it
 * had, so the number reads the same however the customer pays. Two lines of
 * one product (two configurations) take from the same shelf.
 *
 * States: `low` — at or below the threshold, `out` — none left, `oversold` —
 * below zero (sold past the shelf: a preorder, or the count is wrong). ''
 * when there is enough, or the product is gone.
 */
class LowStock
{
    public const LOW = 'low';

    public const OUT = 'out';

    public const OVERSOLD = 'oversold';

    /**
     * The automations' own threshold (a manager wants to hear at 1–2), or the
     * catalogue's "Low stock threshold" — the storefront's "few left" — when
     * that is not set.
     */
    public static function threshold(): int
    {
        $own = (int) setting('torgash.automations', 'low_stock_threshold', 0);

        return $own > 0 ? $own : max(0, (int) setting('torgash.catalog', 'stock_low_threshold', 10));
    }

    /**
     * product id => what is left after this order.
     *
     * @param  Collection<int, Product>|null  $products  the lines' products, keyed by id (one query when null)
     * @return array<int, int>
     */
    public static function left(Order $order, ?Collection $products = null): array
    {
        $taken = [];
        foreach ((array) $order->items as $item) {
            if (is_array($item) && ! empty($item['product_id'])) {
                $taken[(int) $item['product_id']] = ($taken[(int) $item['product_id']] ?? 0) + (int) ($item['qty'] ?? 0);
            }
        }

        $products ??= Product::whereIn('id', array_keys($taken))->get(['id', 'stock'])->keyBy('id');
        $left = [];

        foreach ($taken as $id => $qty) {
            if ($product = $products[$id] ?? null) {
                $left[$id] = (int) $product->stock - ($order->stock_deducted ? 0 : $qty);
            }
        }

        return $left;
    }

    public static function state(?int $left, ?int $threshold = null): string
    {
        return match (true) {
            $left === null => '',
            $left < 0 => self::OVERSOLD,
            $left === 0 => self::OUT,
            $left <= ($threshold ?? self::threshold()) => self::LOW,
            default => '',
        };
    }
}
