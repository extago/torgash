<?php

namespace Torgash\Shipping;

use Illuminate\Support\Collection;
use Throwable;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Shipping\Conditions\CartFacts;
use Torgash\Shipping\Conditions\Conditions;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Models\ShippingRate;
use Torgash\Shipping\Models\Zone;

/**
 * Which delivery options this customer may pick, and what each costs.
 *
 * Three independent questions, in this order (PLAN §12.1):
 *
 *   Is it offered?  The zone gate — a method with no rate in the winning zone
 *                   is not offered there — and, for a carrier method, whether
 *                   that carrier serves the place at all.
 *   What is it?     A carrier method expands into its variants (to branch, to
 *                   locker, by courier), each with its own checkout fields.
 *   What does it cost?  `price_mode`: free, the rate's bands, the carrier's own
 *                   answer, or nothing yet because a human will say.
 *
 * Keeping the last two apart is the point: "use Nova Poshta's branch picker but
 * charge my own per-kilogram table" is a real shop's setup, and so is "let Nova
 * Poshta price it". Tying the carrier to its own pricing would make the first
 * impossible.
 *
 * When the chosen mode cannot produce a number — a weight band over a cart
 * whose weight nobody measured, a carrier that answers nothing — `on_incalculable`
 * decides: hide the method, fall back to the bands, or offer it with the price
 * left to a human (PLAN §12.4).
 *
 * Each returned option has a stable `id` (the value a form submits and an order
 * records): "{method_id}" plain, "{method_id}:{offer_key}" for one variant of a
 * carrier method. An option waiting for a human carries `pending_quote` and the
 * reason.
 */
class ShippingOptions
{
    public function __construct(
        protected ZoneMatcher $matcher,
        protected ShippingProviders $providers,
        protected Conditions $conditions,
    ) {}

    /**
     * @return Collection<int, object> each: {id, offer_key, provider, method,
     *     rate, cost, label, delivery_time, fields_view, fields_data, meta,
     *     zone, pending_quote, quote_reason}
     */
    public function for(Location $location, Package $package, ?CartFacts $facts = null): Collection
    {
        return $this->inZone($this->zone($location), $package, $location, $facts);
    }

    /**
     * The same answer for a zone that has already been matched, so a caller
     * that also has to name the winning zone does not match it twice. The
     * location is still needed: a provider prices against the actual address,
     * not just its zone.
     *
     * @return Collection<int, object>
     */
    public function inZone(?Zone $zone, Package $package, Location $location, ?CartFacts $facts = null): Collection
    {
        if (! $zone) {
            return collect();
        }

        $facts ??= new CartFacts;

        return ShippingRate::query()
            // conditions and the logo eagerly: both are read once per method
            // and a lazy load here would be a query per offered method on
            // every quote — and a quote runs on every keystroke in the town.
            ->with('rows', 'method.conditions', 'method.media')
            ->where('zone_id', $zone->id)
            ->where('enabled', true)
            ->whereHas('method', fn ($query) => $query->where('enabled', true))
            ->get()
            ->sortBy(fn (ShippingRate $rate) => [$rate->method->position, $rate->method->id])
            // The applicability gate, before anything is priced: a method the
            // cart does not qualify for has no price worth computing.
            ->filter(fn (ShippingRate $rate) => $this->conditions->pass($rate->method, $facts, $rate->method->conditions))
            ->flatMap(fn (ShippingRate $rate) => $rate->method->provider
                ? $this->carrierOptions($rate, $package, $zone, $location, $facts)
                : $this->plainOptions($rate, $package, $zone))
            ->values();
    }

    /** The winning zone, or the catch-all while the address is still unknown. */
    public function zone(Location $location): ?Zone
    {
        return $this->matcher->match($location) ?? $this->matcher->fallback();
    }

    /**
     * A method with no carrier: one option, priced by its mode.
     *
     * @return list<object>
     */
    protected function plainOptions(ShippingRate $rate, Package $package, Zone $zone): array
    {
        $cost = $this->cost($rate, $package, $zone);

        return is_array($cost) ? $cost : [$this->option($rate, $zone, cost: $cost)];
    }

    /**
     * A carrier method: the carrier decides WHETHER and INTO WHAT, the mode
     * decides for how much.
     *
     * @return list<object>
     */
    protected function carrierOptions(ShippingRate $rate, Package $package, Zone $zone, Location $location, ?CartFacts $facts = null): array
    {
        $method = $rate->method;
        $provider = $this->providers->get($method->provider);
        $config = $method->provider_config ?? [];

        // Before an address is typed the method simply waits: not knowing where
        // the parcel goes is not the carrier refusing it.
        if ($location->isEmpty()) {
            return [];
        }

        // "I do not deliver there" is a coverage answer, not a pricing failure,
        // so it hides the method outright rather than going through
        // on_incalculable — the same thing a "hide when city not found" setting
        // does, and the reason an unmapped town simply has no Nova Poshta.
        if (! $provider || ! $provider->servesPlace($location, $config)) {
            return [];
        }

        try {
            // The plan, not the package: one shipment today, and whatever a
            // warehouses module decides the day it can split a cart across
            // origins. The default planner is right here so a shop with no such
            // module still asks the carrier the same question.
            $shipments = (array) hook_filter(
                'shipping.shipment.plan',
                [Shipment::single($package, $this->origin($method), $this->lines($facts))],
                ['package' => $package, 'method' => $method, 'location' => $location],
            );

            $offers = $provider->quote($location, $shipments, $config);
        } catch (Throwable $e) {
            // A carrier's API being down must not take the checkout with it.
            report($e);
            $offers = [];
        }

        if ($offers === []) {
            return $this->incalculable($rate, $package, $zone, 'carrier_unavailable');
        }

        // The carrier answered, but without a number — its API refused this
        // destination, or no sender town is configured to price from. When the
        // method is priced BY the carrier that is the same dead end as no offer
        // at all, so its on_incalculable decides; a 0 slipping through here is
        // exactly the "Free" bug. Other modes never read $offer->cost, so a
        // null among the offers is harmless to them.
        if ($method->price_mode === 'carrier'
            && array_filter($offers, fn (ProviderOffer $offer) => $offer->cost === null)) {
            return $this->incalculable($rate, $package, $zone, 'carrier_unavailable');
        }

        $cost = $this->cost($rate, $package, $zone, carrier: true);

        // The mode could not price it (a weight band with no weight); the
        // fallback answers for the whole method, not per variant.
        if (is_array($cost)) {
            return $cost;
        }

        return array_map(fn (ProviderOffer $offer) => $this->option(
            $rate,
            $zone,
            // Only "priced by the carrier" takes the carrier's number; the
            // other modes priced it themselves above. A carrier that answers in
            // its own currency is converted here, so everything after this line
            // is base minor units like every other total in the engine.
            cost: $method->price_mode === 'carrier'
                ? Money::toBase($offer->cost, $offer->currency)
                : $cost,
            offer: $offer,
        ), $offers);
    }

    /**
     * What is in the parcel, for a carrier that has to know more than its
     * weight — pickup, whose "carrier" is a shelf in a shop that either holds
     * all of it or does not. Empty when the quote was made without a cart (the
     * admin's zone tester), and a provider must read that as "unknown".
     *
     * @return list<array{product_id: int, qty: int}>
     */
    protected function lines(?CartFacts $facts): array
    {
        $lines = [];

        foreach ($facts?->quantities ?? [] as $productId => $qty) {
            $lines[] = ['product_id' => (int) $productId, 'qty' => (int) $qty];
        }

        return $lines;
    }

    /**
     * Where the parcel leaves from, or null while nothing in the shop knows.
     *
     * The hook answers with a place id (`['place_id' => 42]`), not with a
     * Location: whoever knows the shop's own address is a warehouses module,
     * and it must be able to say so without referencing checkout's classes —
     * it depends on the catalogue, not on delivery. Resolving the id into a
     * country and a region is this module's job anyway, since it is the one
     * that already asks the place registry that question.
     */
    protected function origin(ShippingMethod $method): ?Location
    {
        $answer = hook_filter('shipping.origin', null, ['method' => $method]);
        $placeId = (int) (is_array($answer) ? ($answer['place_id'] ?? 0) : 0);

        return $placeId > 0 ? Location::forPlace($placeId) : null;
    }

    /**
     * What this method charges, or — when its mode cannot say — the fallback
     * options its `on_incalculable` calls for.
     *
     * @return int|list<object> an amount, or the options to return instead
     */
    protected function cost(ShippingRate $rate, Package $package, Zone $zone, bool $carrier = false): int|array
    {
        return match ($rate->method->price_mode) {
            'free' => 0,
            'quote' => [$this->option($rate, $zone, cost: 0, pending: 'quote_on_request')],
            // A carrier method asks its carrier; a method that names no carrier
            // but claims to be priced by one can never produce a number.
            'carrier' => $carrier ? 0 : $this->incalculable($rate, $package, $zone, 'carrier_unavailable'),
            default => $this->banded($rate, $package, $zone),
        };
    }

    /** @return int|list<object> */
    protected function banded(ShippingRate $rate, Package $package, Zone $zone): int|array
    {
        // Only a rate that actually charges by weight cares: a flat rate, or one
        // banded by order total, prices an unmeasured cart perfectly. WHICH
        // measurement is missing depends on what this method charges on — the
        // scales, the box, or both.
        $missing = $rate->chargesByWeight() ? $rate->method->missingMeasurement($package) : null;

        if ($missing !== null) {
            return $this->incalculable($rate, $package, $zone, $missing);
        }

        return $rate->costFor($package);
    }

    /**
     * What the method said to do when its own mode came up empty.
     *
     * @return list<object>
     */
    protected function incalculable(ShippingRate $rate, Package $package, Zone $zone, string $reason): array
    {
        return match ($rate->method->on_incalculable) {
            'hide' => [],
            // Deliberately the raw bands, weight and all: the admin asked for
            // "charge what the table says" as the answer to not knowing better.
            'bands' => [$this->option($rate, $zone, cost: $rate->costFor($package))],
            default => [$this->option($rate, $zone, cost: 0, pending: $reason)],
        };
    }

    /**
     * One option. A pending one costs 0 in the total and carries why — the
     * order is placed with that reason frozen onto it, and an admin sets the
     * amount afterwards. Deliberately not hidden: "we will call you about
     * delivery" is an order the shop gets; a missing method is one it does not.
     */
    protected function option(
        ShippingRate $rate,
        Zone $zone,
        int $cost,
        ?ProviderOffer $offer = null,
        ?string $pending = null,
    ): object {
        $method = $rate->method;

        return (object) [
            'id' => $offer ? $method->id.':'.$offer->key : (string) $method->id,
            'offer_key' => $offer?->key,
            'provider' => $offer ? $method->provider : null,
            'method' => $method,
            'rate' => $rate,
            'cost' => $cost,
            'label' => $offer && $offer->label !== ''
                ? $method->t('name').' — '.$offer->label
                : $method->t('name'),
            'delivery_time' => $offer?->deliveryTime
                ?? ($rate->delivery_time ? $rate->t('delivery_time') : null),
            'fields_view' => $offer?->fieldsView,
            'fields_data' => $offer?->fieldsData ?? [],
            'meta' => $offer?->meta ?? [],
            // Everything is sent to an address unless the carrier says its
            // variant is not — or, for a method with no carrier, the method
            // itself says so (a plain "Pickup"). Checkout reads this to stop
            // demanding a street the order will never be taken to.
            'needs_address' => $offer?->needsAddress ?? (bool) ($method->needs_address ?? true),
            'zone' => $zone,
            'pending_quote' => $pending !== null,
            'quote_reason' => $pending,
        ];
    }
}
