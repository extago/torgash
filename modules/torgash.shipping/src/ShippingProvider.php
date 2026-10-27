<?php

namespace Torgash\Shipping;

use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;

/**
 * A live carrier behind a shipping method — Nova Poshta, CDEK, a courier API.
 *
 * ONE carrier is ONE provider, but "to branch", "to locker" and "by courier"
 * are separate ShippingMethod rows, all with the same `provider` key and told
 * apart by their `provider_config` (Nova Poshta's service type, warehouse
 * types, sender depot, default box size …). That way each variant is a full
 * method: its own name, its own on/off, its own rate row per zone — "courier
 * only in Kyiv, branch everywhere" is just which zones you priced it in.
 *
 * The module that owns the carrier registers it:
 *
 *     hook_listen('shipping.providers',
 *         fn (array $p) => $p + ['nova_poshta' => NovaPoshtaProvider::class]);
 *
 * Everything here runs on the storefront during a quote, so keep servesPlace()
 * cheap (a local lookup) and let quote() be the one that may call the API.
 */
interface ShippingProvider
{
    /** Display name for the admin's method form. Already translated. */
    public function title(): string;

    /**
     * Blade view for this carrier's section of the shipping-method form — where
     * an admin sets the variant's config (service type, sender depot …). Null
     * for a carrier that needs no per-method settings. Rendered with
     * ['config' => <the method's current provider_config>].
     */
    public function configView(): ?string;

    /**
     * Clean the submitted provider_config before it is stored on the method:
     * keep only known keys, coerce types, drop empties.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizeConfig(array $input): array;

    /**
     * Can this destination be served at all by a method with this config?
     * Called before quote() and on its own by the zone tester, so it must not
     * hit the network — answer from the carrier's synced settlement table.
     *
     * @param  array<string, mixed>  $config  the method's provider_config
     */
    public function servesPlace(Location $location, array $config): bool;

    /**
     * The offers a method with this config has for this cart to this place.
     * Usually one — the variant the config names — but a list, because a
     * carrier may expose sub-choices (express vs standard, redelivery). Empty
     * means nothing right now, and the method is not shown.
     *
     * Takes a LIST of shipments, not one package: an order is not always one
     * parcel (PLAN §12.8). Today the planner always hands over exactly one, so
     * a carrier may simply price `$shipments[0]` — but the price it returns is
     * for the whole plan, and a carrier that wants to charge per parcel sums
     * them itself and puts the breakdown in the offer's `meta`.
     *
     * @param  list<Shipment>  $shipments
     * @param  array<string, mixed>  $config  the method's provider_config
     * @return list<ProviderOffer>
     */
    public function quote(Location $location, array $shipments, array $config): array;

    /**
     * Validate the extra fields submitted for one offer and return the snapshot
     * to freeze onto the order (branch ref, service type, promised date …).
     * Throw Illuminate\Validation\ValidationException on bad input. Return an
     * empty array for an offer that asked for no extra fields.
     *
     * @param  array<string, mixed>  $input  the offer's `shipping_fields`
     * @param  array<string, mixed>  $config  the method's provider_config
     * @return array<string, mixed>
     */
    public function snapshot(Location $location, string $offerKey, array $input, array $config): array;
}
