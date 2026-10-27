<?php

namespace Torgash\Checkout\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $table = 'm_checkout_coupons';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'bool',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** The list's tabs, in the order a merchant scans them. */
    public const STATES = ['active', 'scheduled', 'expired', 'used_up', 'off'];

    /**
     * Where this coupon stands NOW — the switch alone hides the difference
     * between a code that has not started, one that ran out last month and one
     * that works, which is the question the list is opened with. The same
     * checks as isValidFor(), minus the basket's subtotal, which is not the
     * coupon's state but the customer's.
     *
     * @return 'active'|'scheduled'|'expired'|'used_up'|'off'
     */
    public function state(): string
    {
        return match (true) {
            ! $this->enabled => 'off',
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            $this->usage_limit !== null && $this->used_count >= $this->usage_limit => 'used_up',
            $this->starts_at !== null && $this->starts_at->isFuture() => 'scheduled',
            default => 'active',
        };
    }

    /**
     * Re-checked every time the cart is rendered, not just when the code is
     * entered — an expired/exhausted/no-longer-enabled coupon stops
     * discounting automatically without the customer needing to "remove" it.
     */
    public function isValidFor(int $subtotal): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return false;
        }

        if ($this->min_subtotal !== null && $subtotal < $this->min_subtotal) {
            return false;
        }

        return true;
    }

    /** Discount in minor units for a given subtotal (0 if not currently valid). */
    public function discountFor(int $subtotal): int
    {
        if (! $this->isValidFor($subtotal)) {
            return 0;
        }

        $discount = $this->type === 'percent'
            ? round_money($subtotal * $this->value / 100)
            : $this->value;

        return min($discount, $subtotal);
    }
}
