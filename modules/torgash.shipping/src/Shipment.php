<?php

namespace Torgash\Shipping;

use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;

/**
 * One parcel leaving one place.
 *
 * A carrier is asked to price a LIST of these rather than a single package,
 * because an order is not always one parcel: goods sitting in two warehouses
 * either meet at a hub first or leave as they are, and a wardrobe leaves on its
 * own whatever else is in the cart. Today the planner always returns exactly
 * one shipment, so nothing behaves differently — but the shape is the one that
 * survives the day it does not, and a carrier module written against it now
 * needs no rewrite then (PLAN §12.8).
 *
 * `origin` is where it leaves from — filled from the warehouse the shop
 * dispatches from (which now carries a place id, PLAN §20.4/1), and still null
 * in a shop that has no warehouses module or has not said where it is. `lines`
 * is whatever the planner wants the carrier to know about the contents;
 * carriers that only need weight and size ignore it.
 */
final class Shipment
{
    /**
     * @param  list<array{product_id: int, qty: int}>  $lines
     */
    public function __construct(
        public readonly Package $package,
        public readonly ?Location $origin = null,
        public readonly array $lines = [],
    ) {}

    /** The whole cart as a single parcel — what every plan is until warehouses can split one. */
    public static function single(Package $package, ?Location $origin = null, array $lines = []): self
    {
        return new self($package, $origin, $lines);
    }

    /**
     * The contents as product id => quantity — the shape every stock question
     * in the engine is asked in (`catalog.stock.prefer_warehouse` and now
     * `shipping.pickup_points`). Empty when the plan was made without a cart,
     * which is a different answer from "nothing in it": see the callers.
     *
     * @return array<int, int>
     */
    public function quantities(): array
    {
        $quantities = [];

        foreach ($this->lines as $line) {
            $id = (int) ($line['product_id'] ?? 0);

            if ($id > 0) {
                $quantities[$id] = ($quantities[$id] ?? 0) + (int) ($line['qty'] ?? 0);
            }
        }

        return $quantities;
    }

    /**
     * The whole plan's contents, for a carrier that has to answer for all of it
     * at once — pickup does: one point hands over the order or it is not on the
     * list.
     *
     * @param  list<self>  $shipments
     * @return array<int, int>
     */
    public static function combinedLines(array $shipments): array
    {
        $quantities = [];

        foreach ($shipments as $shipment) {
            foreach ($shipment->quantities() as $productId => $qty) {
                $quantities[$productId] = ($quantities[$productId] ?? 0) + $qty;
            }
        }

        return $quantities;
    }

    /**
     * What the whole plan weighs and costs, for a carrier that prices a plan as
     * one consignment rather than parcel by parcel.
     *
     * @param  list<self>  $shipments
     */
    public static function combined(array $shipments): Package
    {
        if (count($shipments) === 1) {
            return $shipments[0]->package;
        }

        $packages = array_map(fn (self $shipment) => $shipment->package, $shipments);

        return new Package(
            subtotal: (int) array_sum(array_map(fn (Package $p) => $p->subtotal, $packages)),
            weight: (int) array_sum(array_map(fn (Package $p) => $p->weight, $packages)),
            items: (int) array_sum(array_map(fn (Package $p) => $p->items, $packages)),
            // One unmeasured parcel makes the whole plan unmeasured: the sum of
            // what is known is not the weight of what is being sent.
            weightKnown: ! in_array(false, array_map(fn (Package $p) => $p->weightKnown, $packages), true),
            volume: (int) array_sum(array_map(fn (Package $p) => $p->volume, $packages)),
            volumeKnown: ! in_array(false, array_map(fn (Package $p) => $p->volumeKnown, $packages), true),
        );
    }
}
