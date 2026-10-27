<?php

namespace Torgash\Checkout\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One order poured into another, written down so it can be undone
 * (Torgash\Checkout\Orders\MergeOrders).
 */
class OrderMerge extends Model
{
    protected $table = 'm_checkout_order_merges';

    protected $guarded = [];

    public static function auditLabel(): string
    {
        return 'Order merge';
    }

    protected function casts(): array
    {
        return [
            'keys' => 'array',
            'into_before' => 'array',
            'from_before' => 'array',
            'moved' => 'array',
            'undone_at' => 'datetime',
        ];
    }

    public function into()
    {
        return $this->belongsTo(Order::class, 'into_id')->withTrashed();
    }

    public function from()
    {
        return $this->belongsTo(Order::class, 'from_id')->withTrashed();
    }

    /**
     * A module's note of what it moved during the merge, for its own
     * `checkout.order.unmerging` listener to move back.
     */
    public function remember(string $module, mixed $data): void
    {
        $moved = (array) $this->moved;
        $moved[$module] = $data;
        $this->update(['moved' => $moved]);
    }

    public function remembered(string $module): mixed
    {
        return ((array) $this->moved)[$module] ?? null;
    }
}
