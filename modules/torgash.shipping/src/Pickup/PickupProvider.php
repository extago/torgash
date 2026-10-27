<?php

namespace Torgash\Shipping\Pickup;

use Illuminate\Validation\ValidationException;
use Torgash\Checkout\Shipping\Location;
use Torgash\Shipping\ProviderOffer;
use Torgash\Shipping\Shipment;
use Torgash\Shipping\ShippingProvider;

/**
 * "Collect it from our shop" as a carrier whose depots are the shop's own.
 *
 * A provider rather than a plain method, because the choice of POINT is the
 * same shape as the choice of a Nova Poshta branch: a list that depends on the
 * town, extra fields under the selected option, and a snapshot frozen onto the
 * order. Reusing that machinery is what keeps this module from growing a second
 * kind of "method with a picker" (PLAN §20.2/2).
 *
 * The points themselves come from whoever owns warehouses, through
 * `shipping.pickup_points`. This module never learns what a warehouse is: it
 * asks for a place, gets back a list of names and addresses, and freezes the
 * chosen id. A shop with no warehouses module gets an empty list, and the
 * method is simply not offered — which is the honest answer, since there would
 * be nowhere to collect from.
 *
 * What it does NOT do is price anything. Collection is free in the overwhelming
 * majority of shops, so an offer costs 0; a shop that charges for picking and
 * packing sets the method's price_mode to `bands` and prices it in the zone
 * like any other method.
 */
class PickupProvider implements ShippingProvider
{
    public const KEY = 'pickup';

    public function title(): string
    {
        return __('Pickup from our points');
    }

    public function configView(): ?string
    {
        return null;
    }

    public function normalizeConfig(array $input): array
    {
        return [];
    }

    /**
     * Cheap by contract (it runs on the zone tester too), and honest: no town
     * named means no list, because a customer cannot choose a shop before they
     * say which town they are in.
     */
    public function servesPlace(Location $location, array $config): bool
    {
        return $location->placeId !== null && $this->points($location) !== [];
    }

    public function quote(Location $location, array $shipments, array $config): array
    {
        // Only the points that hold this whole order. Asked with the contents,
        // so the module that owns the stock can answer it in one query — and
        // asked at all only when the plan knows the contents: a quote made
        // without a cart lists every point rather than none.
        $points = $this->points($location, Shipment::combinedLines($shipments));

        if ($points === []) {
            return [];
        }

        return [new ProviderOffer(
            key: self::KEY,
            label: __('Pickup'),
            // Zero, not null: null means "the carrier could not price this",
            // which sends a price_mode = carrier method through on_incalculable.
            // Collection has a price and it is nothing.
            cost: 0,
            fieldsView: 'torgash.shipping::checkout.pickup-points',
            fieldsData: [
                'points' => $points,
                'chosen' => $this->chosen($points),
            ],
            // Nothing is being sent anywhere: the customer's street is not part
            // of this order, and asking for it would only put a made-up address
            // on it.
            needsAddress: false,
        )];
    }

    public function snapshot(Location $location, string $offerKey, array $input, array $config): array
    {
        $id = (int) ($input['pickup_point'] ?? 0);

        // Re-read rather than trusted: the field comes back with the form after
        // the customer has changed town, and a shop in the previous one must
        // not survive that. The cart is not re-checked here — the option was
        // built from a list that already covered it, and refusing an order at
        // the last click over a unit sold in the meantime would be worse than
        // letting the allocator fall through to another warehouse.
        $point = collect($this->points($location))->firstWhere('id', $id);

        if (! $point) {
            throw ValidationException::withMessages([
                'shipping_fields' => __('Choose where you will collect the order.'),
            ]);
        }

        return array_filter([
            'carrier' => self::KEY,
            // The one key the rest of the engine acts on: the order is taken
            // out of THIS warehouse's stock (ShippingServiceProvider answers
            // catalog.stock.prefer_warehouse with it).
            'pickup_point_id' => $point['id'],
            'pickup_point' => $point['name'],
            'pickup_address' => $point['address'] ?? null,
            'pickup_phone' => $point['phone'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The points on offer for this place, and for these contents when they are
     * known.
     *
     * @param  array<int, int>  $lines  product id => quantity
     * @return list<array<string, mixed>>
     */
    protected function points(Location $location, array $lines = []): array
    {
        return (array) hook_filter('shipping.pickup_points', [], [
            'place_id' => $location->placeId,
            'lines' => $lines,
        ]);
    }

    /**
     * What the form is already carrying, when it is still on offer — the whole
     * fields block is re-rendered on every re-quote, so a choice that lived
     * only in the browser would un-select itself in front of the customer.
     *
     * @param  list<array<string, mixed>>  $points
     */
    protected function chosen(array $points): ?int
    {
        $id = (int) request()->input('shipping_fields.pickup_point', 0);

        return collect($points)->firstWhere('id', $id) ? $id : null;
    }
}
