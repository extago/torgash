<?php

namespace Tests\Fixtures\Checkout;

use Illuminate\Validation\ValidationException;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Shipping\ProviderOffer;
use Torgash\Shipping\ShippingProvider;

/**
 * Stand-in carrier for ShippingProviderTest — the real one (Nova Poshta) is a
 * module. Lives in its own file, not in the test, because PHPUnit loads a test
 * file before the app boots and this one implements a module interface.
 *
 * Config is static: a test arranges the offers before the container resolves
 * the provider list.
 */
class FakeCarrier implements ShippingProvider
{
    public static bool $serves = true;

    /** @var list<ProviderOffer> */
    public static array $offers = [];

    /** @var list<\Torgash\Shipping\Shipment> what the planner handed over last time */
    public static array $lastShipments = [];

    /** The provider_config the last quote() was called with — for assertions. */
    public static ?array $lastConfig = null;

    public static function reset(): void
    {
        self::$serves = true;
        self::$offers = [];
        self::$lastConfig = null;
    }

    public function title(): string
    {
        return 'Fake Carrier';
    }

    public function configView(): ?string
    {
        return null;
    }

    public function normalizeConfig(array $input): array
    {
        // Keep only a known key, to prove the method form is filtered.
        return array_intersect_key($input, ['variant' => true]);
    }

    public function servesPlace(Location $location, array $config): bool
    {
        return self::$serves;
    }

    public function quote(Location $location, array $shipments, array $config): array
    {
        self::$lastConfig = $config;
        self::$lastShipments = $shipments;

        return self::$offers;
    }

    public function snapshot(Location $location, string $offerKey, array $input, array $config): array
    {
        if (empty($input['branch'])) {
            throw ValidationException::withMessages(['shipping_fields' => 'Pick a branch.']);
        }

        return ['offer' => $offerKey, 'branch' => $input['branch'], 'variant' => $config['variant'] ?? null];
    }
}
