<?php

namespace Torgash\Shipping;

/**
 * The registered carriers, keyed by the string a ShippingMethod stores in
 * `provider`.
 *
 * Built fresh from the hook rather than bound as a singleton: a persistent
 * worker (and the test suite's one shared app) would otherwise pin whatever
 * providers were registered when it was first resolved.
 */
class ShippingProviders
{
    /** @var array<string, ShippingProvider> */
    protected array $map = [];

    public function __construct()
    {
        foreach ((array) hook_filter('shipping.providers', []) as $key => $provider) {
            $this->map[$key] = is_string($provider) ? app($provider) : $provider;
        }
    }

    public function get(?string $key): ?ShippingProvider
    {
        return $key !== null ? ($this->map[$key] ?? null) : null;
    }

    public function has(?string $key): bool
    {
        return $key !== null && isset($this->map[$key]);
    }

    /** @return array<string, ShippingProvider> */
    public function all(): array
    {
        return $this->map;
    }

    /**
     * key => title, for the method form's provider picker.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return array_map(fn (ShippingProvider $provider) => $provider->title(), $this->map);
    }
}
