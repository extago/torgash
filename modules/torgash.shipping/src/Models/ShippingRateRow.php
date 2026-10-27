<?php

namespace Torgash\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One band of one axis: "from 5 to 20 kg, 3.00 per kilogram".
 *
 * A rate charges at most one row per axis — the band the cart falls in — and
 * adds the three results together, exactly as established engines do.
 */
class ShippingRateRow extends Model
{
    protected $table = 'm_shipping_rate_rows';

    protected $guarded = [];

    // Source strings, not labels for a menu: "Item count" rather than "Items"
    // so a translation of the word elsewhere in the shop cannot land here.
    public const AXES = [
        'C' => 'Order total',
        'W' => 'Weight',
        'I' => 'Item count',
    ];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Delivery rate band';
    }

    /** Only these two axes have a unit worth multiplying by. */
    public const PER_UNIT_AXES = ['W', 'I'];

    protected function casts(): array
    {
        return [
            'range_from' => 'int',
            'range_to' => 'int',
            'value_amount' => 'int',
            'per_unit' => 'bool',
        ];
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(ShippingRate::class, 'rate_id');
    }

    public function covers(int $amount): bool
    {
        return $amount >= $this->range_from
            && ($this->range_to === null || $amount <= $this->range_to);
    }

    /**
     * What this band adds, in minor units.
     *
     * A percentage is always taken from the order total even on the weight
     * axis — "2% of the order for anything over 20 kg" is a real tariff, "2% of
     * 20 kilograms" is not a number. Same choice older engines made.
     */
    public function costFor(int $amount, int $subtotal): int
    {
        $value = $this->value_type === 'percent'
            ? round_money($subtotal * (float) $this->value_percent / 100)
            : (int) $this->value_amount;

        if (! $this->per_unit || ! in_array($this->axis, self::PER_UNIT_AXES, true)) {
            return $value;
        }

        // Per unit means per kilogram on the weight axis, per item on the other.
        // Weight is kept in grams, so a 2.5 kg parcel pays two and a half times
        // the rate rather than two thousand five hundred times it.
        return $this->axis === 'W'
            ? (int) round($value * $amount / 1000)
            : $value * $amount;
    }
}
