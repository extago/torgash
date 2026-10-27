<?php

namespace Tests\Feature\Cdek;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Torgash\Cdek\Models\PlaceMap;
use Torgash\Cdek\Models\Point;
use Torgash\Cdek\Models\Sync;
use Torgash\Cdek\PointType;
use Torgash\Cdek\Shipping\CdekProvider;
use Torgash\Cdek\Shipping\Notice;
use Torgash\Cdek\Tariff;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Locations\Models\Place;
use Torgash\Shipping\Shipment;
use Torgash\Shipping\ShippingProviders;

/**
 * The module drops in as a third party: its tables are its own, its provider
 * registers through the shipping hook, a place it has no code for is simply
 * not served, and a price is CDEK's whole bill — never a silent zero.
 */
class ModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $settings = app(SettingsManager::class);
        $settings->set('torgash.cdek', 'account', 'acc');
        $settings->set('torgash.cdek', 'secure_password', 'sec');
        $settings->set('torgash.cdek', 'test_mode', false);
        $settings->set('torgash.cdek', 'sender_city_code', 44);
        // Fresh points: no test here should reach for the real directory.
        $settings->set('torgash.cdek', 'points_ttl_hours', 24);
    }

    public function test_its_tables_are_all_m_cdek_prefixed(): void
    {
        foreach (['m_cdek_cities', 'm_cdek_place_map', 'm_cdek_points', 'm_cdek_sync'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "$table should exist");
        }
    }

    public function test_the_carrier_is_registered_with_shipping(): void
    {
        $this->assertArrayHasKey('cdek', app(ShippingProviders::class)->options());
        $this->assertSame('CDEK', app(ShippingProviders::class)->get('cdek')->title());
    }

    public function test_a_place_with_no_mapping_is_not_served(): void
    {
        $place = $this->place();
        $provider = new CdekProvider;
        $location = new Location(country: 'RU', placeId: $place->id);

        $this->assertFalse($provider->servesPlace($location, []));

        PlaceMap::create(['place_id' => $place->id, 'city_code' => 270, 'matched_by' => 'manual']);

        $this->assertTrue($provider->servesPlace($location, []));
    }

    public function test_config_is_whitelisted(): void
    {
        $clean = (new CdekProvider)->normalizeConfig([
            'delivery' => 'teleport',
            'point_types' => [PointType::LOCKER, 'SPACESHIP'],
            'handover' => 'pigeon',
            'tariff' => 'nonsense',
            'calculate' => '0',
            'sneaky' => 'dropme',
        ]);

        $this->assertSame([
            'delivery' => 'point',
            'point_types' => [PointType::LOCKER],
            'handover' => 'office',
            'tariff' => Tariff::PARCEL,
            'calculate' => false,
        ], $clean);

        $courier = (new CdekProvider)->normalizeConfig(['delivery' => 'door', 'point_types' => [PointType::OFFICE]]);
        $this->assertSame([], $courier['point_types'], 'a courier goes to an address, not a point type');
    }

    public function test_the_tariff_follows_the_family_the_handover_and_the_end(): void
    {
        // Measured on the test server's tarifflist (Moscow → St Petersburg).
        $this->assertSame(136, Tariff::code(Tariff::PARCEL, Tariff::mode(false, PointType::OFFICE)));
        $this->assertSame(368, Tariff::code(Tariff::PARCEL, Tariff::mode(false, PointType::LOCKER)));
        $this->assertSame(139, Tariff::code(Tariff::PARCEL, Tariff::mode(true, 'door')));
        $this->assertSame(482, Tariff::code(Tariff::EXPRESS, Tariff::mode(false, 'door')));
        $this->assertNull(Tariff::code(Tariff::ECONOMY, Tariff::mode(false, PointType::LOCKER)), 'economy has no locker variant');
    }

    public function test_a_point_order_must_name_a_point_it_offers_and_carries_that_points_tariff(): void
    {
        $place = $this->mapped();
        $this->point('NSK1', PointType::OFFICE);
        $this->point('NSK2', PointType::LOCKER);
        $provider = new CdekProvider;
        $location = new Location(country: 'RU', placeId: $place->id);

        try {
            $provider->snapshot($location, 'point', [], ['delivery' => 'point']);
            $this->fail('a point order with no point should not be accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shipping_fields', $e->errors());
        }

        // A method that offers offices only cannot be talked into a locker.
        $this->expectException(ValidationException::class);

        $locker = $provider->snapshot($location, 'point', ['point_code' => 'NSK2'], ['delivery' => 'point']);
        $this->assertSame(368, $locker['tariff_code'], 'a locker is sent by the locker tariff, whatever was quoted');
        $this->assertSame('NSK2', $locker['point_code']);

        $provider->snapshot($location, 'point', ['point_code' => 'NSK2'], ['delivery' => 'point', 'point_types' => [PointType::OFFICE]]);
    }

    public function test_a_courier_order_must_carry_an_address(): void
    {
        $place = $this->mapped();
        $provider = new CdekProvider;
        $location = new Location(country: 'RU', placeId: $place->id);

        $snapshot = $provider->snapshot($location, 'door', ['address' => 'ул. Ленина, 1'], ['delivery' => 'door', 'tariff' => Tariff::EXPRESS]);

        $this->assertSame(['carrier' => 'cdek', 'delivery' => 'door', 'tariff_code' => 482, 'city_code' => 270, 'address' => 'ул. Ленина, 1'], $snapshot);

        $this->expectException(ValidationException::class);
        $provider->snapshot($location, 'door', [], ['delivery' => 'door']);
    }

    public function test_the_price_is_cdeks_whole_bill_and_is_asked_once(): void
    {
        $place = $this->mapped();
        $this->point('NSK1', PointType::OFFICE);
        $this->fakeCdek(['delivery_sum' => 715.0, 'total_sum' => 903.0, 'currency' => 'RUB', 'calendar_min' => 1, 'calendar_max' => 3]);

        $offer = $this->quote($place, ['delivery' => 'point'])[0];

        // total_sum, not delivery_sum: measured, the first is what the
        // contract bills — delivery plus VAT plus the insurance.
        $this->assertSame(\Torgash\Shipping\Money::toMinor(903, 'RUB'), $offer->cost);
        $this->assertSame('RUB', $offer->currency);
        $this->assertSame('point', $offer->key);
        $this->assertSame(136, $offer->meta['tariff_code']);
        $this->assertSame(1, $offer->fieldsData['total']);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'calculator/tariff')
            && $request['tariff_code'] === 136
            && $request['from_location'] === ['code' => 44]
            && $request['to_location'] === ['code' => 270]
            && $request['packages'][0]['weight'] === 1500);

        // A quote runs on every keystroke in the town field.
        $this->quote($place, ['delivery' => 'point']);
        Http::assertSentCount(2); // the token, and one price
    }

    public function test_the_insurance_declares_the_cart_in_roubles_from_cents(): void
    {
        // The books keep cents whatever a currency's display decimals say: a
        // $50 cart is 5 000 cents, and at 81 roubles a dollar it is declared
        // as 4 050 — not as 405 000 (which is what dividing by a display
        // setting of 0 decimals declared).
        $place = $this->mapped();
        $this->point('NSK1', PointType::OFFICE);
        $this->fakeCdek(['total_sum' => 900.0, 'currency' => 'RUB']);

        $this->quote($place, ['delivery' => 'point'], subtotal: 5_000);

        $declared = (int) round(50 * (shop_currency() === 'RUB'
            ? 1
            : (float) app(\App\Core\Support\Currencies::class)->find('RUB')?->rate));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'calculator/tariff')
            && ($request['services'][0] ?? null) === ['code' => 'INSURANCE', 'parameter' => (string) $declared]);
    }

    public function test_no_sender_city_means_no_live_price_and_no_request(): void
    {
        app(SettingsManager::class)->set('torgash.cdek', 'sender_city_code', 0);
        $place = $this->mapped();
        $this->point('NSK1', PointType::OFFICE);
        Http::fake();

        $offer = $this->quote($place, ['delivery' => 'point'])[0];

        $this->assertNull($offer->cost, 'null is "the carrier could not price it", never free');
        Http::assertNothingSent();
    }

    public function test_a_refused_price_is_no_price_and_is_not_asked_again_at_once(): void
    {
        $place = $this->mapped();
        $this->point('NSK1', PointType::OFFICE);
        $this->fakeCdek(['errors' => [['code' => 'v2_recipient_location_not_recognized', 'message' => 'nope']]]);

        $this->assertNull($this->quote($place, ['delivery' => 'point'])[0]->cost);
        $this->assertNull($this->quote($place, ['delivery' => 'point'])[0]->cost);

        Http::assertSentCount(2);
    }

    public function test_a_town_with_no_point_that_takes_the_parcel_is_not_offered_and_says_why(): void
    {
        $place = $this->mapped();
        $this->point('NSK1', PointType::LOCKER, weightMax: 20);
        $this->fakeCdek(['total_sum' => 900.0]);

        $this->assertSame([], $this->quote($place, ['delivery' => 'point'], weight: 25_000));
        $this->assertSame(Notice::TOO_HEAVY, app(Notice::class)->reason());
        $this->assertSame(25, app(Notice::class)->params()['kg']);
    }

    public function test_a_locker_too_small_for_the_parcel_is_left_out(): void
    {
        $place = $this->mapped();
        $this->point('NSK1', PointType::LOCKER, cells: [['width' => 10, 'height' => 10, 'depth' => 10]]);
        $this->point('NSK2', PointType::OFFICE);

        $codes = Point::offered(270, [], 1000, 2000)->pluck('code')->all();

        $this->assertSame(['NSK2'], $codes, 'two litres do not fit a one-litre cell');
        $this->assertCount(2, Point::offered(270, [], 1000, 900)->get());
    }

    public function test_the_point_search_answers_the_typing_within_the_methods_types(): void
    {
        $this->point('NSK1', PointType::OFFICE, address: 'ул. Ленина, 5');
        $this->point('NSK2', PointType::LOCKER, address: 'ул. Ленина, 9');
        $this->point('NSK3', PointType::OFFICE, address: 'пр. Мира, 1');

        $html = $this->get('http://localhost/cdek/points?city=270&types=PVZ&point_query='.urlencode('ленина'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ул. Ленина, 5', $html);
        $this->assertStringNotContainsString('ул. Ленина, 9', $html, 'a locker, and this method offers offices only');
        $this->assertStringNotContainsString('пр. Мира', $html);
    }

    public function test_sync_marker_staleness(): void
    {
        $this->assertTrue(Sync::stale('cities', new \DateInterval('PT1H')));

        Sync::mark('cities', ['cities' => 5]);

        $this->assertFalse(Sync::stale('cities', new \DateInterval('PT1H')));
        $this->assertSame(5, Sync::find('cities')->meta['cities']);
    }

    /** @return list<\Torgash\Shipping\ProviderOffer> */
    protected function quote(Place $place, array $config, int $weight = 1500, int $subtotal = 500_00): array
    {
        app(Notice::class)->forget();
        $provider = new CdekProvider;

        return $provider->quote(
            new Location(country: 'RU', placeId: $place->id),
            [Shipment::single(new Package(subtotal: $subtotal, weight: $weight, items: 1))],
            $provider->normalizeConfig($config),
        );
    }

    protected function fakeCdek(array $price): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'T', 'expires_in' => 3600]),
            '*/calculator/tariff' => Http::response($price),
        ]);
    }

    protected function place(): Place
    {
        return Place::create([
            'country' => 'RU', 'name' => ['ru' => 'Новосибирск'], 'type' => 'settlement',
            'latitude' => 55.03, 'longitude' => 82.92, 'population' => 1_600_000, 'enabled' => true,
        ]);
    }

    protected function mapped(): Place
    {
        $place = $this->place();
        PlaceMap::create(['place_id' => $place->id, 'city_code' => 270, 'matched_by' => 'manual']);
        // The city's points are "fresh", so a quote reads the table.
        Sync::mark('points:270');

        return $place;
    }

    protected function point(string $code, string $type, float $weightMax = 30, ?array $cells = null, string $address = 'ул. Тестовая, 1'): void
    {
        DB::table('m_cdek_points')->insert([
            'code' => $code,
            'city_code' => 270,
            'type' => $type,
            'name' => $code,
            'address' => $address,
            'weight_min' => 0,
            'weight_max' => $weightMax,
            'dimensions' => $cells === null ? null : json_encode($cells),
            'schedule' => json_encode([['day' => 1, 'time' => '10:00/20:00']]),
            'is_handout' => true,
            'synced_at' => now(),
        ]);
    }
}
