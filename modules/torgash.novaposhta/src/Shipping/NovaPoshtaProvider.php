<?php

namespace Torgash\NovaPoshta\Shipping;

use Illuminate\Validation\ValidationException;
use Throwable;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\NovaPoshta\Api\Client;
use Torgash\NovaPoshta\Http\BranchesController;
use Torgash\NovaPoshta\Models\PlaceMap;
use Torgash\NovaPoshta\Models\Warehouse;
use Torgash\NovaPoshta\ServiceType;
use Torgash\NovaPoshta\Sync\WarehouseSync;
use Torgash\NovaPoshta\WarehouseType;
use Torgash\Shipping\ProviderOffer;
use Torgash\Shipping\Shipment;
use Torgash\Shipping\ShippingProvider;

/**
 * Nova Poshta as a ShippingProvider. One instance backs every "Nova Poshta …"
 * method; the method's provider_config says which variant it is (pickup or
 * courier) and, for pickup, which kinds of point — a locker being one of them.
 *
 * A place with no mapping is not offered at all (servesPlace) — the mapping is
 * built once, offline, by PlaceMatcher. The branches of the town the customer
 * actually named are fetched lazily and cached on a TTL, so the picker costs
 * one request per town per couple of hours rather than one per checkout.
 */
class NovaPoshtaProvider implements ShippingProvider
{
    public function title(): string
    {
        return 'Nova Poshta';
    }

    public function configView(): ?string
    {
        return 'torgash.novaposhta::admin.method-config';
    }

    public function normalizeConfig(array $input): array
    {
        [$serviceType, $warehouseTypes] = $this->variant($input);

        return [
            'service_type' => $serviceType,
            // Only meaningful for pickup; a courier goes to an address.
            'warehouse_types' => $serviceType === ServiceType::BRANCH ? $warehouseTypes : [],
            'calculate' => (bool) ($input['calculate'] ?? true),
        ];
    }

    /**
     * The variant a config names, and the pickup types it narrows to — read the
     * same way whether the config has just been submitted or was saved long ago.
     *
     * That second case is the point: "to a parcel locker" used to be a third
     * variant, and a method saved then must keep offering exactly lockers. It
     * becomes "to a branch, lockers only", which is the same thing said the new
     * way, and the next save writes it that way for good.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: list<string>}
     */
    protected function variant(array $config): array
    {
        $serviceType = (string) ($config['service_type'] ?? ServiceType::BRANCH);
        $types = array_map('strval', (array) ($config['warehouse_types'] ?? []));

        if ($serviceType === ServiceType::LEGACY_LOCKER) {
            return [ServiceType::BRANCH, [WarehouseType::PARCEL_LOCKER]];
        }

        if (! in_array($serviceType, ServiceType::all(), true)) {
            $serviceType = ServiceType::BRANCH;
        }

        return [$serviceType, array_values(array_intersect($types, array_keys(WarehouseType::all())))];
    }

    public function servesPlace(Location $location, array $config): bool
    {
        return $location->placeId !== null
            && PlaceMap::whereKey($location->placeId)->exists();
    }

    public function quote(Location $location, array $shipments, array $config): array
    {
        $map = $location->placeId ? PlaceMap::find($location->placeId) : null;

        if (! $map) {
            return [];
        }

        [$serviceType, $types] = $this->variant($config);
        $package = Shipment::combined($shipments);

        // Courier delivery leaves from the town's "city", which a village does
        // not always have: its parcels are dispatched from the raion centre,
        // and without that ref there is nothing to address.
        if ($serviceType === ServiceType::COURIER && ! $map->city_ref) {
            return [];
        }

        $fields = $this->fields($serviceType, $types, $map, $package);

        // Pickup with nowhere to hand the parcel over is not an option to show —
        // it is a dead end at the last step of checkout. It IS something to say
        // out loud, though: a village with no branch is the destination a
        // Ukrainian shop meets every week, and a carrier that simply vanishes
        // from the list reads as a broken shop. The page asks this back through
        // `shipping.quote.notices` and offers the nearest towns that do have
        // one; nobody but the provider knows it was asked and came back empty.
        if (ServiceType::usesWarehouse($serviceType) && $fields['data']['total'] === 0) {
            [$reason, $params] = $this->whyEmpty($map, $fields['data']);

            app(Notice::class)->noBranches($location->placeId, $reason, $params);

            return [];
        }

        return [new ProviderOffer(
            key: $this->offerKey($serviceType),
            label: ServiceType::label($serviceType),
            cost: $this->price($map, $package, $config, $shipments[0]->origin ?? null),
            // The carrier quotes hryvnia whatever the shop's own currency is —
            // the shipping module converts once (Torgash\Shipping\Money).
            currency: 'UAH',
            fieldsView: $fields['view'],
            fieldsData: $fields['data'],
            meta: ['settlement_ref' => $map->settlement_ref, 'city_ref' => $map->city_ref],
        )];
    }

    /**
     * Why this town offered nothing — asked in the order of how much the
     * answer is worth knowing.
     *
     * "We could not ask" comes first because it is the only one that is not a
     * fact about the town: the branches are simply not in the table and the
     * carrier refused the last attempt to fetch them, so every other reading
     * of an empty list is a guess. It is also the case that used to be silent
     * — the method vanished for two minutes and came back, and nobody could
     * have told why.
     *
     * "Nothing here takes this parcel" is second because it is the answer with
     * something to do about it, and because an empty list in a town with three
     * hundred points is the one nobody believes: every parcel locker the
     * carrier has stops at 20 kg (§121), so a 21 kg basket empties a whole city
     * at once.
     *
     * @param  array<string, mixed>  $data  what fields() worked out
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function whyEmpty(PlaceMap $map, array $data): array
    {
        if (app(WarehouseSync::class)->pending($map->settlement_ref)) {
            return [Notice::UNKNOWN, []];
        }

        if ((int) ($data['hidden_by_weight'] ?? 0) > 0) {
            return [Notice::TOO_HEAVY, ['kg' => (int) ($data['parcel_kg'] ?? 0)]];
        }

        return [Notice::NONE, []];
    }

    /**
     * The extra fields this variant needs at checkout, and what fills them.
     *
     * @param  list<string>  $types
     * @return array{view: string, data: array<string, mixed>}
     */
    protected function fields(string $serviceType, array $types, PlaceMap $map, Package $package): array
    {
        if (! ServiceType::usesWarehouse($serviceType)) {
            return ['view' => 'torgash.novaposhta::checkout.address', 'data' => []];
        }

        // Fills the cache for this town if it is stale; everything below reads
        // the table, which is also what the picker's search does.
        app(WarehouseSync::class)->for($map->settlement_ref);

        // Every kind of pickup point, narrowed to the types the admin ticked —
        // including or excluding lockers, which is what that list is for.
        $offered = Warehouse::offered(
            $map->settlement_ref,
            ['branch', 'cargo', 'locker'],
            $types,
            $package->weight,
        );

        $total = (clone $offered)->count();

        // How many of the town's points this parcel's WEIGHT takes off the
        // list. Worth counting, because the answer is otherwise a mystery: the
        // carrier gives every parcel locker a 20 kg limit, so a 21 kg basket
        // empties a town of its three hundred lockers at once and what is left
        // reads as a shop with one branch in the city. Asked only when there is
        // a weight to narrow by — a quote runs on every keystroke in the town
        // field.
        $hidden = $package->weight > 0
            ? max(0, Warehouse::offered($map->settlement_ref, ['branch', 'cargo', 'locker'], $types)->count() - $total)
            : 0;

        return [
            'view' => 'torgash.novaposhta::checkout.warehouses',
            'data' => [
                'settlement' => $map->settlement_ref,
                'types' => $types,
                'weight' => $package->weight,
                'total' => $total,
                // …and what to say about it, in the customer's own terms.
                'hidden_by_weight' => $hidden,
                'parcel_kg' => (int) ceil($package->weight / 1000),
                // What the customer already picked, if it is still on offer.
                // A quote re-runs and re-renders this whole block whenever the
                // destination or the method changes, so a choice that lived
                // only in the browser would be silently thrown away — and the
                // customer would watch their branch un-select itself. The value
                // travels back with the form, and the server is the one that
                // freezes it onto the order, so it is the thing to trust.
                'chosen' => $this->chosen($offered),
                // Only the first pageful goes into the page: a city's whole
                // branch list would be a few hundred kilobytes in every quote,
                // and a quote runs on every keystroke in the town field.
                'warehouses' => (clone $offered)->inBranchOrder()->limit(BranchesController::PER_PAGE)->get(),
            ],
        ];
    }

    /**
     * The branch the form is carrying, when this variant still offers it.
     *
     * Re-validated rather than trusted: the same field comes back after the
     * customer has changed town or switched to lockers, and a branch in the
     * previous town must not survive that.
     */
    protected function chosen($offered): ?Warehouse
    {
        $ref = trim((string) request()->input('shipping_fields.warehouse_ref', ''));

        return $ref === '' ? null : (clone $offered)->where('ref', $ref)->first();
    }

    /**
     * What the carrier charges for this parcel, in hryvnia, or null when there
     * is no live price to be had — the calculate flag is off, no sender town is
     * configured, the destination has no city ref, the API errored, or it
     * answered with nothing.
     *
     * null is not "free": a `price_mode = bands` method never consults this and
     * prices Nova Poshta from its own rate table, which is a supported setup; a
     * `price_mode = carrier` method treats null as carrier_unavailable and lets
     * its on_incalculable decide (PLAN §13.1).
     */
    protected function price(PlaceMap $map, Package $package, array $config, ?Location $origin = null): ?int
    {
        $sender = $this->senderRef($origin);

        if (! ($config['calculate'] ?? true) || $sender === '' || ! $map->city_ref) {
            return null;
        }

        $grams = $package->weight ?: (int) setting('torgash.novaposhta', 'default_weight_g', 500);

        try {
            $rows = app(Client::class)->call('InternetDocument', 'getDocumentPrice', [
                'CitySender' => $sender,
                'CityRecipient' => $map->city_ref,
                // Always the branch tariff, lockers included: measured, the
                // carrier will not price WarehousePostomat at all ("Not enough
                // parameters" on every set tried), so asking for it would only
                // turn a live price into no price.
                'ServiceType' => ServiceType::BRANCH,
                'CargoType' => 'Parcel',
                'Weight' => (string) round(max(0.1, $grams / 1000), 2),
                // Declared value: the carrier prices insurance off it, and the
                // goods total is the honest number to declare.
                'Cost' => (string) max(1, (int) round($package->subtotal / 100)),
                'SeatsAmount' => '1',
            ]);
        } catch (Throwable $e) {
            // A carrier's API being down must not empty the checkout: the
            // method stays, and the shipping module's fallback decides what
            // "no price" means for it.
            report($e);

            return null;
        }

        $cost = (int) round((float) ($rows[0]['Cost'] ?? 0));

        // A "successful" response with no positive cost is no price either.
        return $cost > 0 ? $cost : null;
    }

    /**
     * Which town the carrier prices FROM: the warehouse this parcel leaves,
     * and the module's own setting only when the shop has not said.
     *
     * The specific beats the general on purpose. A shipment's origin is a fact
     * about this parcel and will differ per parcel the day a cart can be split
     * across warehouses; the setting is one town for the whole shop and cannot
     * ever express that. It stays as the answer for a shop with no warehouses
     * module, or one that has not put its warehouse on the map.
     *
     * The origin's place must be a mapped "city": the carrier's price call
     * takes a CitySender, and a village dispatches through its raion centre —
     * the same asymmetry the destination side already handles.
     */
    protected function senderRef(?Location $origin): string
    {
        $ref = $origin?->placeId ? PlaceMap::find($origin->placeId)?->city_ref : null;

        return trim((string) ($ref ?: setting('torgash.novaposhta', 'sender_city_ref', '')));
    }

    protected function offerKey(string $serviceType): string
    {
        return $serviceType === ServiceType::COURIER ? 'courier' : 'branch';
    }

    public function snapshot(Location $location, string $offerKey, array $input, array $config): array
    {
        [$serviceType] = $this->variant($config);

        if (ServiceType::usesWarehouse($serviceType)) {
            $ref = trim((string) ($input['warehouse_ref'] ?? ''));

            if ($ref === '') {
                throw ValidationException::withMessages([
                    'shipping_fields' => __('Choose a Nova Poshta branch.'),
                ]);
            }

            $warehouse = Warehouse::find($ref);

            return array_filter([
                'carrier' => 'nova_poshta',
                'service_type' => $serviceType,
                'warehouse_ref' => $ref,
                'warehouse_number' => $warehouse?->number ?: null,
                'warehouse_name' => $warehouse?->name_uk ?: null,
            ], fn ($v) => $v !== null);
        }

        $address = trim((string) ($input['address'] ?? ''));

        if ($address === '') {
            throw ValidationException::withMessages([
                'shipping_fields' => __('Enter the delivery address for the courier.'),
            ]);
        }

        return [
            'carrier' => 'nova_poshta',
            'service_type' => $serviceType,
            'address' => $address,
        ];
    }
}
