<?php

namespace Torgash\Shipping;

/**
 * One thing a ShippingProvider can do for a cart: a variant, its price, and
 * (optionally) the extra checkout fields it needs before an order can be placed
 * with it.
 *
 * `key` is unique within its method — "branch", "locker", "courier" — and rides
 * in the submitted id as "{method_id}:{key}", which is how the order knows
 * which variant was chosen without a column per carrier.
 */
final class ProviderOffer
{
    /**
     * @param  string  $key  stable per method: 'branch', 'locker', 'courier'
     * @param  string  $label  shown after the method name; already translated
     * @param  ?int  $cost  minor units of $currency, or null when the carrier
     *     could not price this destination. Only consulted when the method's
     *     price_mode is 'carrier'; a null there means the same dead end as no
     *     offer, so on_incalculable decides — never a silent 0 shown as "Free".
     * @param  ?string  $currency  what $cost is in, or null for the shop's base
     *     currency. A carrier that charges hryvnia says so and is converted
     *     once (Torgash\Shipping\Money) — nothing downstream learns about it.
     * @param  ?string  $deliveryTime  free text ("1–2 days"), or null to fall back to the rate's
     * @param  ?string  $fieldsView  blade view rendered under this offer when it is selected, or null
     * @param  array<string, mixed>  $fieldsData  data passed to that view
     * @param  array<string, mixed>  $meta  anything the provider wants echoed back to itself
     * @param  bool  $needsAddress  whether a street address is part of the order
     *     at all. False for collection: there is nowhere to send it, and making
     *     the customer invent an address to get past the form is how a pickup
     *     order ends up carrying a fictional one. Checkout relaxes the field
     *     for exactly the option that says so, never shop-wide.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?int $cost,
        public readonly ?string $currency = null,
        public readonly ?string $deliveryTime = null,
        public readonly ?string $fieldsView = null,
        public readonly array $fieldsData = [],
        public readonly array $meta = [],
        public readonly bool $needsAddress = true,
    ) {}
}
