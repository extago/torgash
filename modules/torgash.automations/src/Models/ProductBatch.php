<?php

namespace Torgash\Automations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The products one thing happened to at once — what a rule about products is
 * about (Subjects\ProductSubject, PLAN §64.2). `items` is `[{id, before,
 * after}]`: the stock, or for a price event the price in price units, on
 * either side.
 *
 * A rule's conditions NARROW the items of the copy it holds — "only the ones
 * in this category" — so the message lists what the rule asked about.
 */
class ProductBatch extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'm_automations_product_batches';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'created_at' => 'datetime'];
    }

    /** @return list<int> */
    public function productIds(): array
    {
        return array_values(array_map(fn ($item) => (int) ($item['id'] ?? 0), (array) $this->items));
    }

    /** Keep the items a rule still speaks of. @param  callable(array<string, mixed>): bool  $keep */
    public function narrow(callable $keep): void
    {
        $this->items = array_values(array_filter((array) $this->items, $keep));
    }
}
