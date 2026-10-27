<?php

namespace Torgash\Catalog\Support;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Label;

/**
 * What a set of product cards needs worked out for ALL of them before the
 * first one is drawn — the grid and the carousel both draw cards, and both
 * would otherwise pay per card for the same three things.
 */
class ProductCards
{
    /**
     * @param  iterable<\Torgash\Catalog\Models\Product>|Paginator  $products
     * @return array{products: Collection, labels: array<int, mixed>, short: array<int, string>}
     */
    public static function prime(iterable $products): array
    {
        $set = $products instanceof Paginator ? collect($products->items()) : collect($products);

        // Listeners that decorate a single card get the whole set first, so a
        // per-product lookup can be one query for the grid — see the admin
        // list's identical call and StockAllocator::prime().
        hook_action('catalog.products.listed', ['products' => $set]);

        return [
            'products' => $set,
            'labels' => Label::forProducts($set),
            // A card asks for a line composed from a relation, which is exactly
            // the shape that turns a page of 12 products into 12 extra queries.
            'short' => app(ShortDescription::class)->forAll($set, ShortDescription::PLACE_CARD),
        ];
    }
}
