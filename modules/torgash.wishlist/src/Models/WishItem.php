<?php

namespace Torgash\Wishlist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Torgash\Catalog\Models\Product;

/** A product in a wishlist, with the price it had when it was put there. */
class WishItem extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'm_wishlist_items';

    protected $fillable = ['list_id', 'product_id', 'price_at_add'];

    protected $casts = ['price_at_add' => 'integer'];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Wishlist item';
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(WishList::class, 'list_id');
    }
}
