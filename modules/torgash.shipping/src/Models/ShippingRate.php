<?php

namespace Torgash\Shipping\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Torgash\Checkout\Shipping\Package;
use Torgash\Shipping\Money;

/**
 * What one method costs in one zone. Its existence is also the permission to
 * offer that method there at all — see ShippingOptions.
 */
class ShippingRate extends Model
{
    use HasTranslations;

    protected $table = 'm_shipping_rates';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Delivery rate';
    }

    protected function casts(): array
    {
        return [
            'delivery_time' => 'array',
            'enabled' => 'bool',
            'base_rate' => 'int',
            'free_over' => 'int',
        ];
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ShippingRateRow::class, 'rate_id');
    }

    /**
     * Base rate plus one band per axis. "Free above" wins over everything: a
     * shop that promises free delivery above a threshold means free, not free
     * except for the per-kilogram surcharge.
     */
    public function costFor(Package $package): int
    {
        // Everything the admin typed is in THIS rate's currency; everything the
        // package carries is in the base one. So the comparison happens in base
        // units, and the amounts are converted once at the end rather than band
        // by band, which would round three times instead of once.
        if ($this->free_over !== null && $package->subtotal >= Money::toBase($this->free_over, $this->currency)) {
            return 0;
        }

        $own = $this->base_rate;
        $base = 0;

        foreach (Package::AXES as $axis) {
            $amount = $this->amountFor($axis, $package);

            if (! $row = $this->bandFor($axis, $amount)) {
                continue;
            }

            // A percentage is taken FROM the order total, so it is already in
            // base units and must not be converted again.
            if ($row->value_type === 'percent') {
                $base += $row->costFor($amount, $package->subtotal);
            } else {
                $own += $row->costFor($amount, $package->subtotal);
            }
        }

        return max(0, Money::toBase($own, $this->currency) + $base);
    }

    /**
     * What this rate reads on one axis.
     *
     * Only the weight axis is negotiable: the method decides whether that means
     * the scales, the box, or the greater of the two (see
     * ShippingMethod::billableWeight). The bands themselves stay exactly as the
     * admin wrote them — "0–5 kg costs 3.00" is the same sentence whichever
     * weight it is read with, which is why volumetric weight needed no new
     * band type and no formula language.
     */
    public function amountFor(string $axis, Package $package): int
    {
        return $axis === 'W' && $this->method
            ? $this->method->billableWeight($package)
            : $package->amount($axis);
    }

    /**
     * Does this rate's price depend on what the parcel weighs?
     *
     * Asked before charging a cart whose weight is not fully known: a flat rate
     * or one banded by order total prices such a cart perfectly, and refusing
     * to price it would be theatre. Only a weight band makes the missing
     * grams matter.
     */
    public function chargesByWeight(): bool
    {
        return $this->rows->contains(fn (ShippingRateRow $row) => $row->axis === 'W');
    }

    /**
     * The narrowest band that still covers the amount — highest starting point
     * first, so overlapping bands resolve the way the admin reading them top to
     * bottom would expect, and a gap between bands simply charges nothing.
     */
    public function bandFor(string $axis, int $amount): ?ShippingRateRow
    {
        return $this->rows
            ->where('axis', $axis)
            ->sortByDesc('range_from')
            ->first(fn (ShippingRateRow $row) => $row->covers($amount));
    }
}
