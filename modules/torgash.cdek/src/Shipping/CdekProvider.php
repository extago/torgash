<?php

namespace Torgash\Cdek\Shipping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;
use Torgash\Cdek\Api\Client;
use Torgash\Cdek\Http\PointsController;
use Torgash\Cdek\Models\PlaceMap;
use Torgash\Cdek\Models\Point;
use Torgash\Cdek\PointType;
use Torgash\Cdek\Roubles;
use Torgash\Cdek\Sync\PointSync;
use Torgash\Cdek\Tariff;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Shipping\Money;
use Torgash\Shipping\ProviderOffer;
use Torgash\Shipping\Shipment;
use Torgash\Shipping\ShippingProvider;

/**
 * CDEK as a ShippingProvider. One instance backs every "CDEK …" method; the
 * method's provider_config says which variant it is:
 *
 *   delivery     'point' (a pickup point or locker the customer picks) or
 *                'door' (a courier to their address);
 *   point_types  for 'point', which kinds: PointType::OFFICE / LOCKER, none
 *                ticked = both;
 *   handover     how the SHOP hands parcels to CDEK: 'office' (drops them off)
 *                or 'door' (CDEK's courier collects) — half of the tariff;
 *   tariff       the family, Tariff::PARCEL / EXPRESS / ECONOMY — the other
 *                half;
 *   calculate    ask CDEK for the price, or leave it to the method's own
 *                rate table.
 *
 * A place with no mapping is not offered at all (servesPlace) — the mapping is
 * built once, offline, by PlaceMatcher.
 */
class CdekProvider implements ShippingProvider
{
    /** How long one live price is reused — a quote runs on every keystroke. */
    public const PRICE_TTL = 1800;

    /** How long a refused price is not asked again. */
    public const FAILURE_TTL = 120;

    public function title(): string
    {
        return 'CDEK';
    }

    public function configView(): ?string
    {
        return 'torgash.cdek::admin.method-config';
    }

    public function normalizeConfig(array $input): array
    {
        $variant = $this->variant($input);

        return [
            'delivery' => $variant['delivery'],
            // Only meaningful for a point; a courier goes to an address.
            'point_types' => $variant['delivery'] === 'point' ? $variant['types'] : [],
            'handover' => $variant['handover'],
            'tariff' => $variant['tariff'],
            'calculate' => (bool) ($input['calculate'] ?? true),
        ];
    }

    /**
     * The config read the same way whether just submitted or saved long ago:
     * anything unknown falls back to the default rather than to an error.
     *
     * @param  array<string, mixed>  $config
     * @return array{delivery: string, types: list<string>, handover: string, tariff: string, calculate: bool}
     */
    protected function variant(array $config): array
    {
        $delivery = ($config['delivery'] ?? 'point') === 'door' ? 'door' : 'point';
        $types = array_values(array_intersect(
            array_map('strval', (array) ($config['point_types'] ?? [])),
            array_keys(PointType::all()),
        ));
        $tariff = (string) ($config['tariff'] ?? Tariff::PARCEL);

        return [
            'delivery' => $delivery,
            'types' => $delivery === 'point' ? $types : [],
            'handover' => ($config['handover'] ?? 'office') === 'door' ? 'door' : 'office',
            'tariff' => array_key_exists($tariff, Tariff::CODES) ? $tariff : Tariff::PARCEL,
            'calculate' => (bool) ($config['calculate'] ?? true),
        ];
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

        $variant = $this->variant($config);
        $package = Shipment::combined($shipments);
        $fields = $this->fields($variant, $map->city_code, $package);

        // A pickup method with nowhere to pick up is a dead end at the last
        // step of checkout — not offered, but explained underneath (Notice).
        if ($variant['delivery'] === 'point' && $fields['data']['total'] === 0) {
            [$reason, $params] = $this->whyEmpty($map->city_code, $fields['data']);

            app(Notice::class)->noPoints($location->placeId, $reason, $params);

            return [];
        }

        $tariff = $this->tariffCode($variant, $this->priceEnd($variant));
        $price = $variant['calculate'] ? $this->price($map->city_code, $package, $tariff, $shipments[0]->origin ?? null) : null;

        return [new ProviderOffer(
            key: $variant['delivery'],
            label: $variant['delivery'] === 'door' ? __('By courier') : __('To a pickup point'),
            cost: $price['cost'] ?? null,
            // CDEK quotes in the contract's currency, roubles for a Russian
            // one; the shipping module converts once (Torgash\Shipping\Money).
            currency: $price['currency'] ?? 'RUB',
            deliveryTime: $price ? $this->days($price['min'], $price['max']) : null,
            fieldsView: $fields['view'],
            fieldsData: $fields['data'],
            meta: ['city_code' => $map->city_code, 'tariff_code' => $tariff],
        )];
    }

    /**
     * Where the parcel is priced TO before the customer has picked a point:
     * an office, unless the method offers lockers alone. The order itself
     * carries the tariff of the point actually chosen (snapshot()).
     *
     * @param  array{delivery: string, types: list<string>}  $variant
     */
    protected function priceEnd(array $variant): string
    {
        if ($variant['delivery'] === 'door') {
            return 'door';
        }

        return $variant['types'] === [PointType::LOCKER] ? PointType::LOCKER : PointType::OFFICE;
    }

    /** @param  array{handover: string, tariff: string}  $variant */
    protected function tariffCode(array $variant, string $end): ?int
    {
        return Tariff::code($variant['tariff'], Tariff::mode($variant['handover'] === 'door', $end));
    }

    /**
     * Why this town offered nothing — "we could not ask" first, because it is
     * the only one that is not a fact about the town; "nothing here takes this
     * parcel" second, because it is the one with something to do about it.
     *
     * @param  array<string, mixed>  $data  what fields() worked out
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function whyEmpty(int $cityCode, array $data): array
    {
        if (app(PointSync::class)->pending($cityCode)) {
            return [Notice::UNKNOWN, []];
        }

        if ((int) ($data['hidden_by_size'] ?? 0) > 0) {
            return [Notice::TOO_HEAVY, ['kg' => (int) ($data['parcel_kg'] ?? 0)]];
        }

        return [Notice::NONE, []];
    }

    /**
     * The extra fields this variant needs at checkout, and what fills them.
     *
     * @param  array{delivery: string, types: list<string>}  $variant
     * @return array{view: string, data: array<string, mixed>}
     */
    protected function fields(array $variant, int $cityCode, Package $package): array
    {
        if ($variant['delivery'] === 'door') {
            return ['view' => 'torgash.cdek::checkout.address', 'data' => []];
        }

        app(PointSync::class)->for($cityCode);

        $volume = $package->volumeKnown ? $package->volume : 0;
        $offered = Point::offered($cityCode, $variant['types'], $package->weight, $volume);
        $total = (clone $offered)->count();

        // How many of the town's points this parcel's weight or size takes off
        // the list — otherwise a heavy basket reads as a town with two points.
        $hidden = $package->weight > 0 || $volume > 0
            ? max(0, Point::offered($cityCode, $variant['types'])->count() - $total)
            : 0;

        return [
            'view' => 'torgash.cdek::checkout.points',
            'data' => [
                'city' => $cityCode,
                'types' => $variant['types'],
                'weight' => $package->weight,
                'volume' => $volume,
                'total' => $total,
                'hidden_by_size' => $hidden,
                'parcel_kg' => (int) ceil($package->weight / 1000),
                // What the customer already picked, if it is still on offer:
                // the block re-renders on every re-quote, and a choice that
                // lived only in the browser would un-select itself.
                'chosen' => $this->chosen($offered),
                'points' => (clone $offered)->inListOrder()->limit(PointsController::PER_PAGE)->get(),
            ],
        ];
    }

    /** The point the form is carrying, when this variant still offers it. */
    protected function chosen($offered): ?Point
    {
        $code = trim((string) request()->input('shipping_fields.point_code', ''));

        return $code === '' ? null : (clone $offered)->where('code', $code)->first();
    }

    /**
     * What CDEK charges for this parcel, or null when there is no live price
     * to be had — no sender city, no such tariff for this route, the API
     * refused or is down.
     *
     * null is not "free": a `price_mode = bands` method never consults this,
     * and a `price_mode = carrier` method lets its on_incalculable decide.
     *
     * `total_sum` is the number, not `delivery_sum`: measured, the first adds
     * VAT and the insurance, and it is what the contract actually bills
     * (858 against 715 for the same parcel).
     *
     * @return array{cost: int, currency: string, min: ?int, max: ?int}|null
     */
    protected function price(int $cityCode, Package $package, ?int $tariff, ?Location $origin = null): ?array
    {
        $sender = $this->senderCode($origin);

        if ($sender <= 0 || $tariff === null) {
            return null;
        }

        $grams = $package->weight ?: (int) setting('torgash.cdek', 'default_weight_g', 500);
        [$length, $width, $height] = $this->box($package);

        $body = [
            'tariff_code' => $tariff,
            'from_location' => ['code' => $sender],
            'to_location' => ['code' => $cityCode],
            'packages' => [[
                'weight' => max(1, $grams),
                'length' => $length,
                'width' => $width,
                'height' => $height,
            ]],
        ];

        if (setting('torgash.cdek', 'insure', true) && ($declared = $this->declared($package)) > 0) {
            $body['services'] = [['code' => 'INSURANCE', 'parameter' => (string) $declared]];
        }

        $client = app(Client::class);
        $key = 'torgash.cdek.price.'.md5(json_encode([$client->test(), $body]));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached['price'];
        }

        try {
            $answer = $client->quick()->post('calculator/tariff', $body);
            $sum = (float) ($answer['total_sum'] ?? $answer['delivery_sum'] ?? 0);
            $currency = (string) ($answer['currency'] ?? 'RUB');

            // A "successful" answer with no positive sum is no price either.
            $price = $sum > 0 ? [
                'cost' => Money::toMinor($sum, $currency),
                'currency' => $currency,
                'min' => isset($answer['calendar_min']) ? (int) $answer['calendar_min'] : null,
                'max' => isset($answer['calendar_max']) ? (int) $answer['calendar_max'] : null,
            ] : null;
        } catch (Throwable $e) {
            // The API being down must not empty the checkout, and must not be
            // asked again on the very next keystroke either.
            report($e);
            Cache::put($key, ['price' => null], self::FAILURE_TTL);

            return null;
        }

        Cache::put($key, ['price' => $price], $price ? self::PRICE_TTL : self::FAILURE_TTL);

        return $price;
    }

    /** "1–2 days", "3 days" — calendar days, which is what a customer counts. */
    protected function days(?int $min, ?int $max): ?string
    {
        if ($max === null || $max <= 0) {
            return null;
        }

        $min = max(1, (int) $min);

        return $min >= $max
            ? trans_choice(':count day|:count days', $max)
            : __(':min–:max days', ['min' => $min, 'max' => $max]);
    }

    /**
     * The parcel's box in cm. The default box from the settings, unless the
     * goods' own volume does not fit in it — then a cube that holds them,
     * because a carrier bills a big parcel on its size, and a price for a box
     * it would not fit in is a price nobody pays.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    protected function box(Package $package): array
    {
        $length = max(1, (int) setting('torgash.cdek', 'default_length_cm', 20));
        $width = max(1, (int) setting('torgash.cdek', 'default_width_cm', 20));
        $height = max(1, (int) setting('torgash.cdek', 'default_height_cm', 10));

        if ($package->volume > $length * $width * $height) {
            $side = (int) ceil($package->volume ** (1 / 3));

            return [$side, $side, $side];
        }

        return [$length, $width, $height];
    }

    /**
     * The goods' value in roubles, for the insurance. The package total is in
     * the shop's own currency; a rouble shop declares it as it is, any other
     * converts through its rate for RUB and declares nothing if it has none.
     *
     * Hundredths whatever the currency's `decimals` say — those are how it is
     * DISPLAYED; the books keep cents (format_price divides by 100 too).
     * Dividing by the display decimals declared a $5 000 cart as 40 million
     * roubles on a shop that shows whole dollars.
     */
    protected function declared(Package $package): int
    {
        return (int) round(Roubles::fromMinor($package->subtotal) ?? 0);
    }

    /**
     * Which city the carrier prices FROM: the warehouse this parcel leaves,
     * and the module's own setting only when the shop has not said.
     */
    protected function senderCode(?Location $origin): int
    {
        $code = $origin?->placeId ? PlaceMap::find($origin->placeId)?->city_code : null;

        return (int) ($code ?: setting('torgash.cdek', 'sender_city_code', 0));
    }

    public function snapshot(Location $location, string $offerKey, array $input, array $config): array
    {
        $variant = $this->variant($config);
        $cityCode = $location->placeId ? PlaceMap::find($location->placeId)?->city_code : null;

        if ($variant['delivery'] === 'point') {
            $code = trim((string) ($input['point_code'] ?? ''));
            $point = $code === '' ? null : Point::find($code);

            if (! $point || ! $point->is_handout || ($variant['types'] !== [] && ! in_array($point->type, $variant['types'], true))) {
                throw ValidationException::withMessages([
                    'shipping_fields' => __('Choose a CDEK pickup point.'),
                ]);
            }

            return array_filter([
                'carrier' => 'cdek',
                'delivery' => 'point',
                // The tariff of the point actually chosen — a locker is sent
                // by a different code from an office, whatever was quoted.
                'tariff_code' => $this->tariffCode($variant, $point->type === PointType::LOCKER ? PointType::LOCKER : PointType::OFFICE),
                'city_code' => $point->city_code ?: $cityCode,
                'point_code' => $point->code,
                'point_type' => $point->type,
                'point_name' => $point->name ?: null,
                'point_address' => $point->address_full ?: ($point->address ?: null),
            ], fn ($value) => $value !== null);
        }

        $address = trim((string) ($input['address'] ?? ''));

        if ($address === '') {
            throw ValidationException::withMessages([
                'shipping_fields' => __('Enter the delivery address for the courier.'),
            ]);
        }

        return array_filter([
            'carrier' => 'cdek',
            'delivery' => 'door',
            'tariff_code' => $this->tariffCode($variant, 'door'),
            'city_code' => $cityCode,
            'address' => $address,
        ], fn ($value) => $value !== null);
    }
}
