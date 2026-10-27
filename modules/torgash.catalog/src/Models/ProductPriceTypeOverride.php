<?php

namespace Torgash\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A flat adjustment_percent doesn't fit every SKU — one row here overrides it outright. */
class ProductPriceTypeOverride extends Model
{
    protected $table = 'm_catalog_product_price_type_overrides';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Own price for a price type';
    }

    protected function casts(): array
    {
        return ['price' => 'int', 'price_original' => 'int'];
    }

    /** The amount as typed, in the product's `price_currency`; `price` is the books amount it derives. */
    public function typedPrice(): int
    {
        return $this->price_original ?? $this->price;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceType(): BelongsTo
    {
        return $this->belongsTo(PriceType::class);
    }
}
