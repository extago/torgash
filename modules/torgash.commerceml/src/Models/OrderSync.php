<?php

namespace Torgash\CommerceMl\Models;

use Illuminate\Database\Eloquent\Model;
use Torgash\Checkout\Models\Order;

/**
 * One order's standing with 1C: what it was told, and what it has answered.
 *
 * Kept beside the order rather than on it, because "has 1C seen this" is a fact
 * about the exchange and not about the sale — uninstall the module and the
 * question stops existing, along with the table.
 */
class OrderSync extends Model
{
    protected $table = 'm_commerceml_order_sync';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Order sent to 1C';
    }

    protected function casts(): array
    {
        return [
            'offered_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** 1C already has this exact version of the order. */
    public function isCurrent(string $hash): bool
    {
        return $this->confirmed_hash === $hash;
    }
}
