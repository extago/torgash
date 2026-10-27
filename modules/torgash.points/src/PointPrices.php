<?php

namespace Torgash\Points;

use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;

/**
 * A product's price in points — the shop's option (`points_prices`), shown
 * under the ordinary price and paid for a whole basket line at once. The table
 * holds a handful of rows in any real shop, so it is read once per request.
 */
class PointPrices
{
    /** @var array<int, int>|null */
    protected ?array $prices = null;

    public function enabled(): bool
    {
        return (bool) setting('torgash.points', 'points_prices', false);
    }

    /** The price in points, or null when the product has none (or the shop sells for none). */
    public function for(Product|int|null $product): ?int
    {
        if (! $this->enabled() || ! $product) {
            return null;
        }

        $this->prices ??= DB::table('m_points_products')->pluck('points_price', 'product_id')->map('intval')->all();

        return $this->prices[$product instanceof Product ? (int) $product->id : (int) $product] ?? null;
    }
}
