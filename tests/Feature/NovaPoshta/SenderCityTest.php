<?php

namespace Tests\Feature\NovaPoshta;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Locations\Models\Place;
use Torgash\NovaPoshta\Models\PlaceMap;
use Torgash\NovaPoshta\ServiceType;
use Torgash\NovaPoshta\Shipping\NovaPoshtaProvider;
use Torgash\Shipping\Shipment;

/**
 * Which town the carrier prices FROM.
 *
 * A price is from somewhere to somewhere, and until warehouses could be put on
 * the map the "from" was a single module setting typed by hand. Now a shipment
 * carries its origin (PLAN §20.4/1), and the parcel's own warehouse beats the
 * shop-wide setting: the setting cannot ever express two warehouses, and it is
 * the general answer to what is now a specific question.
 *
 * Courier, not branch, so the quote is only a price call — the branch variant
 * would fetch a town's whole branch list first, which is another test's subject.
 */
class SenderCityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsManager::class)->set('torgash.novaposhta', 'api_key', 'test-key');
        app(SettingsManager::class)->set('torgash.novaposhta', 'sender_city_ref', 'setting-city-ref');
    }

    public function test_the_parcel_is_priced_from_the_warehouse_it_leaves(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => [['Cost' => 205]]])]);

        $destination = $this->mapped('warehouse-city-ref');
        $origin = $this->mapped('lviv-city-ref');

        $offers = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $destination->id),
            [Shipment::single(new Package(subtotal: 100_00), Location::forPlace($origin->id))],
            ['service_type' => ServiceType::COURIER, 'calculate' => true],
        );

        $this->assertSame(205, $offers[0]->cost);
        $this->assertSame('lviv-city-ref', $this->sentSender());
    }

    /**
     * A shop with no warehouses module, or one that has not said where its
     * warehouse is, keeps the behaviour it had: the module's own setting.
     */
    public function test_without_an_origin_the_setting_still_answers(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => [['Cost' => 205]]])]);

        $destination = $this->mapped('warehouse-city-ref');

        (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $destination->id),
            [Shipment::single(new Package(subtotal: 100_00))],
            ['service_type' => ServiceType::COURIER, 'calculate' => true],
        );

        $this->assertSame('setting-city-ref', $this->sentSender());
    }

    /**
     * A warehouse in a town Nova Poshta does not serve is not a sender the
     * carrier can be given — the setting answers rather than the price call
     * going out with an empty CitySender and coming back "Data is invalid".
     */
    public function test_an_unmapped_origin_falls_back_to_the_setting(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => [['Cost' => 205]]])]);

        $destination = $this->mapped('warehouse-city-ref');
        $unmapped = Place::create(['country' => 'UA', 'name' => ['uk' => 'Село'], 'type' => 'settlement', 'enabled' => true]);

        (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $destination->id),
            [Shipment::single(new Package(subtotal: 100_00), Location::forPlace($unmapped->id))],
            ['service_type' => ServiceType::COURIER, 'calculate' => true],
        );

        $this->assertSame('setting-city-ref', $this->sentSender());
    }

    /** The CitySender of the price call that actually went out. */
    protected function sentSender(): ?string
    {
        $sender = null;

        Http::assertSent(function ($request) use (&$sender) {
            $body = json_decode($request->body(), true);

            if (($body['calledMethod'] ?? '') === 'getDocumentPrice') {
                $sender = $body['methodProperties']['CitySender'] ?? null;
            }

            return true;
        });

        return $sender;
    }

    protected function mapped(string $cityRef): Place
    {
        $place = Place::create([
            'country' => 'UA', 'name' => ['uk' => 'Місто'], 'type' => 'settlement', 'enabled' => true,
        ]);

        PlaceMap::create([
            'place_id' => $place->id,
            'settlement_ref' => 'settlement-'.$cityRef,
            'city_ref' => $cityRef,
            'matched_by' => 'manual',
            'confidence' => 100,
            'checked_at' => now(),
        ]);

        return $place;
    }
}
