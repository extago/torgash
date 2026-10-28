<?php

namespace Torgash\Shipping;

use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\LocationStore;
use Torgash\Checkout\Shipping\Package;
use Torgash\Checkout\Shipping\ProductBasket;
use Torgash\Shipping\Conditions\CartFacts;

/**
 * "Delivery to <your town>" on the product page: what this one product would
 * cost to send where the visitor says they are, by each method on offer, with
 * its term.
 *
 * Through the cart's own path — a ProductBasket, Package::forCart(),
 * CartFacts::forCart(), ShippingOptions::inZone() — so the zone, the
 * conditions, the weight and the box are the ones the cart will use, and the
 * page cannot promise a price the cart then changes. The town is the one the
 * visitor chose (LocationStore — the "your town" window or checkout); none
 * chosen is the catch-all zone, and the block says so and asks.
 */
class ProductEstimate
{
    public function __construct(
        protected ShippingOptions $options,
        protected LocationStore $locations,
    ) {}

    /**
     * NULL for a product nobody sends (a service, a gift card).
     *
     * @return array{location: Location, town: ?string, options: Collection<int, object>}|null
     */
    public function for(Product $product): ?array
    {
        $basket = new ProductBasket($product);

        if (! $basket->needsDelivery()) {
            return null;
        }

        $location = $this->locations->current();
        $zone = $this->options->zone($location);

        return [
            'location' => $location,
            'town' => $this->townIn($location),
            'options' => $this->options->inZone(
                $zone,
                Package::forCart($basket),
                $location,
                CartFacts::forCart($basket, $basket->total()),
            ),
        ];
    }

    /**
     * The town as "delivery to …" needs it — where the parcel GOES, the
     * accusative (4 — "в Днепр", "в Москву"), not where something is (6 —
     * "в Днепре"); asked of whoever keeps the towns (`locations.place.forms`)
     * so this module names none of their classes; else what was typed into
     * the town box.
     */
    protected function townIn(Location $location): ?string
    {
        if ($location->placeId) {
            $form = hook_filter('locations.place.forms', [], ['ids' => [$location->placeId], 'case' => 4])[$location->placeId] ?? null;

            if (is_string($form) && trim($form) !== '') {
                return $form;
            }
        }

        return $this->locations->town();
    }
}
