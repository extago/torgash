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

    /**
     * product id => at how many pieces it is "running low": the product's own
     * line (PLAN §64.4), else the shop's — and never less than what it sells
     * in the days the shop set (§64.5: "left for fewer than N days"), where
     * somebody keeps a record of what is sold (`catalog.products.sold`,
     * answered by `torgash.inventory` from its journal).
     *
     * @param  iterable<object>  $products  anything with `id` and `low_stock_threshold`
     * @return array<int, int>
     */
    public static function thresholds(iterable $products): array
    {
        $shop = self::threshold();
        $lines = [];

        foreach ($products as $product) {
            $lines[(int) $product->id] = $product->low_stock_threshold !== null ? (int) $product->low_stock_threshold : $shop;
        }

        $days = max(0, (int) setting('torgash.automations', 'low_stock_days', 0));

        if ($days > 0 && $lines !== []) {
            $sold = (array) hook_filter('catalog.products.sold', [], ['ids' => array_keys($lines), 'days' => $days]);

            foreach ($sold as $id => $count) {
                // "Less than it sells in N days" — so the last piece of that many is still enough.
                if (isset($lines[(int) $id])) {
                    $lines[(int) $id] = max($lines[(int) $id], (int) $count - 1);
                }
            }
        }

        return $lines;
    }

    /**
     * product id => its state after this order ('' — enough), each by its own line.
     *
     * @param  Collection<int, Product>|null  $products
     * @return array<int, string>
     */
    public static function states(Order $order, ?Collection $products = null): array
    {
        $taken = array_filter(array_map(fn ($item) => is_array($item) ? (int) ($item['product_id'] ?? 0) : 0, (array) $order->items));
        $products ??= Product::whereIn('id', $taken)->get(['id', 'stock', 'low_stock_threshold'])->keyBy('id');
        $lines = self::thresholds($products);
        $states = [];

        foreach (self::left($order, $products) as $id => $left) {
            $states[$id] = self::state($left, $lines[$id] ?? null);
        }

        return $states;
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
