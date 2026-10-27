<?php

namespace Torgash\Checkout\Shipping;

/**
 * Where the customer wants their order, in the only terms zones care about.
 *
 * A plain value object rather than a query against the locations registry,
 * because checkout must keep working with that module uninstalled: then only
 * the country and the postal code are filled and zones still match on those.
 * The registry, when present, answers checkout.location.for_place to fill in
 * the rest.
 */
class Location
{
    public function __construct(
        public readonly ?string $country = null,
        public readonly ?string $state = null,
        public readonly ?int $placeId = null,
        public readonly ?string $postal = null,
    ) {}

    /**
     * Builds a location from a chosen place, asking whoever owns the registry
     * to say which country and region it is in.
     */
    public static function forPlace(int $placeId, ?string $postal = null): self
    {
        $resolved = hook_filter('checkout.location.for_place', [], ['place_id' => $placeId]);

        return new self(
            country: $resolved['country'] ?? null,
            state: $resolved['state'] ?? null,
            placeId: $placeId,
            postal: $postal ?? ($resolved['postal'] ?? null),
        );
    }

    public function isEmpty(): bool
    {
        return $this->country === null && $this->placeId === null && $this->postal === null;
    }
}
