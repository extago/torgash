<?php

namespace Torgash\Giftcards\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A catalogue product that IS a gift card, and how its amount is chosen: the
 * product's own price (different amounts are variations, like any other
 * product), or an amount the customer types between a minimum and a maximum.
 */
class GiftProduct extends Model
{
    protected $table = 'm_giftcards_products';

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'free_amount' => 'bool',
            'min_amount' => 'int',
            'max_amount' => 'int',
        ];
    }

    /** The smallest amount a customer may type: the product's own, else the shop's. */
    public function minimum(): int
    {
        return (int) ($this->min_amount ?? round((float) setting('torgash.giftcards', 'min_amount', 100) * 100));
    }

    /** The largest, or null for no limit. */
    public function maximum(): ?int
    {
        $shop = (int) round((float) setting('torgash.giftcards', 'max_amount', 0) * 100);

        return $this->max_amount ?? ($shop > 0 ? $shop : null);
    }
}
