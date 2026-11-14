<?php

namespace Torgash\Cart;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Torgash\Cart\Http\CartController;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Seo\ProductFacts;
use Torgash\Catalog\Seo\ProductsEvent;

/**
 * What changed in the basket, told to the page (PLAN §73).
 *
 * Every answer that changes the basket raises `torgash:cart-changed`
 * (HX-Trigger) — the badge and checkout's note on the minimum order listen
 * for it. It also says WHAT (ProductsEvent): the lines `added` and `removed`,
 * each with the quantity that moved, at the price per unit the basket charged.
 */
class CartEvents
{
    public const MAX_LINES = ProductsEvent::MAX_LINES;

    /**
     * The HX-Trigger header's value.
     *
     * @param  list<array<string, mixed>>  $added
     * @param  list<array<string, mixed>>  $removed
     */
    public static function header(array $added = [], array $removed = []): string
    {
        return ProductsEvent::header(CartController::CHANGED, $added, $removed);
    }

    /**
     * Basket lines as the page is told of them — the products' brands and
     * categories read for all of them at once, not line by line.
     *
     * @param  iterable<object>  $lines  as CartManager::items() prices them
     * @param  array<string, int>|null  $moved  line key => the quantity that moved; null — each line's whole quantity
     * @param  int  $limit  lines described at most: a header's are few, a page's (CheckoutStarted) may be more
     * @return list<array<string, mixed>>
     */
    public static function lines(iterable $lines, ?array $moved = null, int $limit = self::MAX_LINES): array
    {
        $lines = collect($lines)
            ->filter(fn (object $line) => $moved === null || isset($moved[$line->key]))
            ->take($limit)->values();

        (new EloquentCollection($lines->pluck('product')->all()))
            ->loadMissing(['categories', 'featureAssignments.feature', 'featureAssignments.featureValue']);

        return $lines
            ->map(fn (object $line) => self::line($line->product, (int) $line->unit_price, (int) ($moved[$line->key] ?? $line->qty)))
            ->all();
    }

    /**
     * One line that moved: the price per unit this visitor was charged,
     * configuration included (price units).
     *
     * @return array<string, mixed>
     */
    public static function line(Product $product, int $unitPrice, int $quantity): array
    {
        return (new ProductFacts($product))->item($unitPrice, $quantity);
    }

    /**
     * What moved between two prices of the same line — the quantity went up,
     * or down, or the line is gone (`$after` null).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} [added, removed]
     */
    public static function between(?object $before, ?object $after): array
    {
        $was = (int) ($before->qty ?? 0);
        $now = (int) ($after->qty ?? 0);

        return match (true) {
            $now > $was && $after !== null => [[self::line($after->product, (int) $after->unit_price, $now - $was)], []],
            $now < $was && $before !== null => [[], [self::line($before->product, (int) $before->unit_price, $was - $now)]],
            default => [[], []],
        };
    }
}
