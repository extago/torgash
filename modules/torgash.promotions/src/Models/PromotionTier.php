<?php

namespace Torgash\Promotions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One discount depth within a promotion: a condition scope plus a discount.
 * Tiers are tried in `position` order and the first match wins — see the
 * migration's docblock.
 */
class PromotionTier extends Model
{
    protected $table = 'm_promotions_tiers';

    protected $guarded = [];

    public const PERCENT = 'percent';
    public const FIXED = 'fixed';

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Promotion step';
    }

    /**
     * The other half of the usual four: PERCENT/FIXED say HOW MUCH to take
     * off, these two say WHAT THE PRICE BECOMES. `to_percent` leaves the price
     * at N% of the original ("everything at a third of the price"),
     * `to_amount` sets it outright ("everything at 999"), which is the
     * plainest sale a shop ever runs and the one a storefront can print
     * without doing arithmetic for the customer.
     *
     * Not a new kind of behaviour — the same discountFor() computed from the
     * far end — which is why they cost two constants and two match arms rather
     * than a column.
     */
    public const TO_PERCENT = 'to_percent';
    public const TO_AMOUNT = 'to_amount';

    public const FREE_SHIPPING = 'free_shipping';

    /**
     * Types whose `discount_value` is MONEY in minor units rather than a
     * percent or nothing at all — the admin form divides by 100 on the way in
     * and multiplies on the way out, and gets it wrong for exactly one type
     * if this list is not the single answer to "is this a money field".
     */
    public const MONEY_VALUE_TYPES = [self::FIXED, self::TO_AMOUNT];

    /** Cart zone only, and NOT a price adjustment — see isSideEffect(). */
    public const GIVE_GROUP = 'give_group';

    public const FREE_PRODUCT = 'free_product';

    public const PRICE_TYPES = [self::PERCENT, self::FIXED, self::TO_PERCENT, self::TO_AMOUNT, self::FREE_SHIPPING];

    /** The four that adjust a price and can therefore carry a scope. */
    public const ADJUSTING_TYPES = [self::PERCENT, self::FIXED, self::TO_PERCENT, self::TO_AMOUNT];

    public const SIDE_EFFECT_TYPES = [self::GIVE_GROUP, self::FREE_PRODUCT];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'discount_scope' => 'array'];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * give_group/free_product are not price lines — never while a live cart
     * is merely being re-priced (CartPricing::lines() skips them entirely; a
     * customer must never be upgraded or gifted a product just because a page
     * re-rendered the cart total). The free-gift LINE ITEM is added exactly
     * once, at real order placement (checkout.order.items) — a later
     * recalculation edits its amount bookkeeping like any discount, but the
     * gift line itself is then an ordinary order line, adjusted by hand like
     * any other if the admin edits the order's contents.
     */
    public function isSideEffect(): bool
    {
        return in_array($this->discount_type, self::SIDE_EFFECT_TYPES, true);
    }

    /**
     * Restricts a percent/fixed discount to the matching lines' own total
     * ("discount on products / categories") rather than the
     * whole subtotal. Null means "the whole subtotal".
     *
     * @return array{type: 'product'|'category', ids: list<int>}|null
     */
    public function scope(): ?array
    {
        return $this->discount_scope;
    }

    /** @return list<array<string, mixed>> */
    public function rules(): array
    {
        return $this->conditions['rules'] ?? [];
    }

    public function matchAny(): bool
    {
        return ($this->conditions['match'] ?? 'all') === 'any';
    }

    /**
     * Discount off $price in minor units — never more than the price itself,
     * and never less than nothing.
     *
     * The `to_*` pair is stated as a TARGET and turned into a cut here, so
     * every caller keeps subtracting one number and no pricing code has to
     * know which of the four kinds it is holding. A target ABOVE the price is
     * not a surcharge: "everything down to 999" leaves a 500 item at 500,
     * which is why the floor is here rather than trusted to the caller.
     */
    public function discountFor(int $price): int
    {
        $value = (int) ($this->discount_value ?? 0);

        $discount = match ($this->discount_type) {
            self::PERCENT => round_money($price * $value / 100),
            self::FIXED => $value,
            self::TO_PERCENT => $price - round_money($price * $value / 100),
            self::TO_AMOUNT => $price - $value,
            default => 0,
        };

        return max(0, min($discount, $price));
    }
}
