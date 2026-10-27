<?php

namespace Torgash\Checkout\Shipping;

use Illuminate\Support\Collection;

/**
 * One answer to "what can this cart be delivered as, and for how much".
 *
 * The cart page, the checkout form and the order being placed all need the
 * same four things — where it goes, which zone that is, what is offered there
 * and which of those the customer picked — and they must agree. Passing one
 * object around is what makes them agree; three screens each assembling their
 * own is how the price shown stops matching the price charged.
 */
final class Quote
{
    /**
     * @param  Collection<string, object>  $options  keyed by shipping method id
     */
    public function __construct(
        public readonly Location $location,
        // A Torgash\Shipping\Models\Zone when the shipping module is installed,
        // null otherwise — display-only here, so kept untyped to keep this DTO
        // free of a dependency on that module.
        public readonly ?object $zone,
        public readonly Collection $options,
        public readonly ?object $selected,
        public readonly int $subtotal,
        public readonly int $discount,
    ) {}

    /** What the goods cost after any coupon — what shipping bands are measured against. */
    public function payable(): int
    {
        return max(0, $this->subtotal - $this->discount);
    }

    public function shippingCost(): int
    {
        return $this->selected?->cost ?? 0;
    }

    /**
     * The chosen option has no price yet and a human will name one, so its
     * zero must not be shown as "free" anywhere. Set by the delivery module
     * (see its ShippingOptions); false whenever nothing is chosen or no
     * delivery module is installed at all.
     */
    public function shippingPending(): bool
    {
        return (bool) ($this->selected->pending_quote ?? false);
    }

    /** Has the customer actually said where they are, or is this the catch-all quote? */
    public function isAddressed(): bool
    {
        return ! $this->location->isEmpty();
    }

    /**
     * The only ids an order may be placed with — one per offered option.
     * "{method_id}" for a flat-rate method, "{method_id}:{offer_key}" for one
     * variant of a provider method.
     *
     * @return list<string>
     */
    public function offerIds(): array
    {
        return $this->options->keys()->map(fn ($key) => (string) $key)->all();
    }

    public function option(int|string|null $offerId): ?object
    {
        return $offerId === null ? null : $this->options->get((string) $offerId);
    }
}
