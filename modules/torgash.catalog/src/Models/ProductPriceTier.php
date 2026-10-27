<?php

namespace Torgash\Catalog\Models;

use App\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceTier extends Model
{
    protected $table = 'm_catalog_product_prices';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Quantity price';
    }

    protected function casts(): array
    {
        return ['price' => 'int', 'price_original' => 'int', 'min_qty' => 'int'];
    }

    /**
     * The amount as the admin typed it — in the product's `price_currency`, or
     * the books' own when the product has none. `price` is the books amount
     * derived from it and is what the cart and every discount actually read.
     */
    public function typedPrice(): int
    {
        return $this->price_original ?? $this->price;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Null = every customer group. */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }
}
