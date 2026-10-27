<?php

namespace Tests\Feature\NovaPoshta;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Models\Zone;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Shipping\ShippingOptions;
use Torgash\Shipping\ShippingProviders;
use Torgash\Shipping\ZoneMatcher;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;
use Torgash\NovaPoshta\Models\PlaceMap;
use Torgash\NovaPoshta\Models\Sync;
use Torgash\NovaPoshta\ServiceType;
use Torgash\NovaPoshta\Shipping\NovaPoshtaProvider;
use Torgash\NovaPoshta\WarehouseType;

/**
 * The module drops in as a third party: its tables are its own, its provider
 * registers through the checkout hook, and until its data is synced it simply
 * offers nothing — never an error.
 */
class ModuleTest extends TestCase
{
    public function test_its_tables_are_all_m_np_prefixed(): void
    {
        foreach (['m_np_settlements', 'm_np_place_map', 'm_np_warehouses', 'm_np_sync'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "$table should exist");
        }
    }

    public function test_the_carrier_is_registered_with_checkout(): void
    {
        $this->assertArrayHasKey('nova_poshta', app(ShippingProviders::class)->options());
        $this->assertSame('Nova Poshta', app(ShippingProviders::class)->get('nova_poshta')->title());
    }

    public function test_a_place_with_no_mapping_is_not_served(): void
    {
        $place = $this->place();
        $provider = new NovaPoshtaProvider;

        $this->assertFalse($provider->servesPlace(new Location(country: 'UA', placeId: $place->id), []));

        PlaceMap::create(['place_id' => $place->id, 'settlement_ref' => 'settle-1', 'matched_by' => 'manual']);

        $this->assertTrue($provider->servesPlace(new Location(country: 'UA', placeId: $place->id), []));
    }

    public function test_config_is_whitelisted(): void
    {
        $provider = new NovaPoshtaProvider;

        $clean = $provider->normalizeConfig([
            'service_type' => 'nonsense',
            'warehouse_types' => [WarehouseType::POST_OFFICE, 'not-a-ref'],
            'calculate' => '1',
            'sneaky' => 'dropme',
        ]);

        $this->assertSame(ServiceType::BRANCH, $clean['service_type'], 'an unknown service type falls back to branch');
        $this->assertSame([WarehouseType::POST_OFFICE], $clean['warehouse_types'], 'unknown warehouse refs are dropped');
        $this->assertTrue($clean['calculate']);
        $this->assertArrayNotHasKey('sneaky', $clean);
    }

    public function test_warehouse_types_are_cleared_for_a_non_branch_variant(): void
    {
        $clean = (new NovaPoshtaProvider)->normalizeConfig([
            'service_type' => ServiceType::COURIER,
            'warehouse_types' => [WarehouseType::POST_OFFICE],
        ]);

        $this->assertSame([], $clean['warehouse_types']);
    }

    public function test_the_old_locker_variant_is_read_as_a_branch_method_ticked_down_to_lockers(): void
    {
        // Two variants, not three: a locker is a KIND of pickup point, so it is
        // a tick beside the others. A method saved when it was its own variant
        // has to keep doing exactly what it did, and say so the new way after
        // the next save.
        $this->assertSame([ServiceType::BRANCH, ServiceType::COURIER], ServiceType::all());

        $clean = (new NovaPoshtaProvider)->normalizeConfig([
            'service_type' => ServiceType::LEGACY_LOCKER,
        ]);

        $this->assertSame(ServiceType::BRANCH, $clean['service_type']);
        $this->assertSame([WarehouseType::PARCEL_LOCKER], $clean['warehouse_types']);
    }

    public function test_a_branch_order_must_name_a_branch(): void
    {
        $provider = new NovaPoshtaProvider;
        $config = ['service_type' => ServiceType::BRANCH];

        try {
            $provider->snapshot(new Location(country: 'UA'), 'np', [], $config);
            $this->fail('a branch order with no branch should not be accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shipping_fields', $e->errors());
        }

        $snapshot = $provider->snapshot(new Location(country: 'UA'), 'np', ['warehouse_ref' => 'wh-7'], $config);

        $this->assertSame('nova_poshta', $snapshot['carrier']);
        $this->assertSame('wh-7', $snapshot['warehouse_ref']);
        $this->assertSame(ServiceType::BRANCH, $snapshot['service_type']);
    }

    public function test_a_courier_order_must_carry_an_address(): void
    {
        $provider = new NovaPoshtaProvider;
        $config = ['service_type' => ServiceType::COURIER];

        $this->expectException(ValidationException::class);
        $provider->snapshot(new Location(country: 'UA'), 'np', [], $config);
    }

    public function test_until_data_is_synced_the_carrier_offers_nothing_but_flat_rates_are_fine(): void
    {
        $place = $this->place();
        PlaceMap::create(['place_id' => $place->id, 'settlement_ref' => 'settle-1', 'matched_by' => 'manual']);

        $np = ShippingMethod::create([
            'name' => ['en' => 'Nova Poshta — branch'], 'provider' => 'nova_poshta',
            'provider_config' => ['service_type' => ServiceType::BRANCH], 'price_mode' => 'carrier',
            'position' => 60, 'enabled' => true,
        ]);
        $np->rates()->create(['zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 0, 'enabled' => true]);

        $options = app(ShippingOptions::class)
            ->for(new Location(country: 'UA', placeId: $place->id), new Package(subtotal: 10_00));
        $offered = $options->map(fn ($o) => $o->method->name['en'])->all();

        // The place IS mapped, so the carrier serves it — but quote() is still
        // a stub, so it produces no number. With the default fallback the
        // method is still offered, priced by a human afterwards, rather than
        // disappearing from a checkout the customer is standing in.
        $this->assertContains('Nova Poshta — branch', $offered);
        $np = $options->firstWhere('method.id', $np->id);
        $this->assertTrue($np->pending_quote);
        $this->assertSame('carrier_unavailable', $np->quote_reason);
        $this->assertSame(0, $np->cost);

        // …and the seeded flat-rate method is untouched.
        $this->assertContains('Courier delivery', $offered);
    }

    public function test_a_place_with_no_mapping_is_not_offered_the_carrier_at_all(): void
    {
        // No PlaceMap row: the carrier does not serve this town. That is a
        // coverage answer, not a pricing failure, so it hides the method
        // whatever on_incalculable says — the same thing a "hide when city not
        // found" setting does.
        $place = $this->place();

        $np = ShippingMethod::create([
            'name' => ['en' => 'Nova Poshta — branch'], 'provider' => 'nova_poshta',
            'provider_config' => ['service_type' => ServiceType::BRANCH], 'price_mode' => 'carrier',
            'on_incalculable' => 'quote', 'position' => 60, 'enabled' => true,
        ]);
        $np->rates()->create(['zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 0, 'enabled' => true]);

        $offered = app(ShippingOptions::class)
            ->for(new Location(country: 'UA', placeId: $place->id), new Package(subtotal: 10_00))
            ->map(fn ($o) => $o->method->name['en'])
            ->all();

        $this->assertNotContains('Nova Poshta — branch', $offered);
        $this->assertContains('Courier delivery', $offered);
    }

    public function test_sync_marker_staleness(): void
    {
        $this->assertTrue(Sync::stale('settlements', new \DateInterval('PT1H')), 'a missing marker is stale');

        Sync::mark('settlements', ['rows' => 5]);

        $this->assertFalse(Sync::stale('settlements', new \DateInterval('PT1H')));
        $this->assertTrue(Sync::stale('settlements', new \DateInterval('PT0S')));
        $this->assertSame(5, Sync::find('settlements')->meta['rows']);
    }

    protected function place(): Place
    {
        Country::firstOrCreate(['code' => 'UA'], ['name' => ['en' => 'Ukraine']]);

        return Place::create([
            'country' => 'UA', 'name' => ['en' => 'Kyiv'], 'geonameid' => 703448,
            'population' => 3_000_000, 'source' => 'import',
        ]);
    }
}
