<?php

namespace Torgash\Points\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What one order does with points: `earn` is what it WILL earn (worked out at
 * placement and again when its contents change, shown as "waiting"), `earned`
 * what it was credited, `spent` / `spent_money` what the customer paid with.
 */
class OrderPoints extends Model
{
    protected $table = 'm_points_orders';

    protected $primaryKey = 'order_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'earn' => 'int',
            'earned' => 'int',
            'spent' => 'int',
            'spent_money' => 'int',
            'credited_at' => 'datetime',
        ];
    }
}
