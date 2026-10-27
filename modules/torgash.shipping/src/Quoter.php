<?php

namespace Torgash\Shipping;

use Torgash\Cart\Contracts\Basket;
use Torgash\Checkout\CouponManager;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Checkout\Shipping\Quote;
use Torgash\Shipping\Conditions\CartFacts;

/**
 * Builds a Quote for a cart going to a place.
 *
 * The coupon lives in here rather than in each caller because the discount
 * changes what shipping costs — a "free above 50" threshold is measured on
 * what the customer actually pays — so a screen that quoted shipping without
 * subtracting the coupon would quote a different price than checkout charges.
 */
class Quoter
{
    public function __construct(
        protected ShippingOptions $options,
        protected CouponManager $coupons,
    ) {}

    public function for(Basket $cart, Location $location, int|string|null $selectedMethodId = null): Quote
    {
        $subtotal = $cart->total();
        $discount = $this->coupons->discount();

        // Nothing here is sent anywhere (PLAN §40.10/2): no zone, no options,
        // nothing to choose — and PlaceOrder asks for no delivery.
        if (! $cart->needsDelivery()) {
            return new Quote(location: $location, zone: null, options: collect(), selected: null, subtotal: $subtotal, discount: $discount);
        }

        // Matched once and handed to the options: asking the matcher again for
        // the zone to display would be a second lookup for an answer we hold.
        $zone = $this->options->zone($location);
        $payable = max(0, $subtotal - $discount);
        $options = $this->options
            ->inZone(
                $zone,
                Package::forCart($cart, $payable),
                $location,
                // Gathered here because this is the one place that has the cart
                // AND the coupon: a "free over 500" condition has to measure
                // what the customer pays, not what the goods list for.
                CartFacts::forCart($cart, $payable),
            )
            ->keyBy(fn (object $option) => (string) $option->id);

        return new Quote(
            location: $location,
            zone: $zone,
            options: $options,
            // Falling back to the first offer is what makes the summary show a
            // price the moment the zone changes: the option the customer had
            // selected may simply not be offered where they now say they are.
            selected: $options->get((string) $selectedMethodId) ?? $options->first(),
            subtotal: $subtotal,
            discount: $discount,
        );
    }
}
