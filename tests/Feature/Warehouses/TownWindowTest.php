<?php

namespace Tests\Feature\Warehouses;

use App\Core\Settings\SettingsManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Shipping\LocationStore;
use Torgash\Locations\Models\Place;
use Torgash\Warehouses\Models\Warehouse;
use Torgash\Warehouses\PointStore;
use Torgash\Warehouses\StockAllocator;
use Torgash\Warehouses\Transfers;

/**
 * "Your town" (PLAN §38): the window the header's corner opens — the town
 * (torgash.locations), the shop's points in it and the customer's own switch
 * "stock at the chosen shop" (torgash.warehouses), the blue notice over a
 * listing that has become one shop's assortment, and "we will bring it by".
 */
class TownWindowTest extends TestCase
{
    protected Place $kyiv;

    protected Place $lviv;

    protected Warehouse $shop;

    protected Warehouse $depot;

    protected Product $here;

    protected Product $away;

    protected function setUp(): void
    {
        parent::setUp();

        Warehouse::query()->update(['enabled' => false, 'is_default' => false]);
        // A town is offered only in a country the shop serves (Place::available()).
        \Torgash\Locations\Models\Country::query()->updateOrCreate(['code' => 'UA'], ['name' => ['en' => 'Ukraine'], 'enabled' => true]);

        $this->kyiv = Place::create(['country' => 'UA', 'name' => ['en' => 'Kyivtown'], 'type' => 'settlement', 'enabled' => true, 'latitude' => 50.45, 'longitude' => 30.52, 'population' => 2900000]);
        $this->lviv = Place::create(['country' => 'UA', 'name' => ['en' => 'Lvivtown'], 'type' => 'settlement', 'enabled' => true, 'latitude' => 49.84, 'longitude' => 24.03, 'population' => 700000]);

        $this->shop = Warehouse::create(['name' => ['en' => 'Podil shop'], 'address' => 'Kontraktova sq. 1', 'place_id' => $this->kyiv->id, 'enabled' => true, 'is_public' => true, 'is_pickup' => true]);
        $this->depot = Warehouse::create(['name' => ['en' => 'Depot'], 'place_id' => $this->lviv->id, 'enabled' => true, 'is_public' => false, 'is_pickup' => false, 'transfer_days' => 2]);

        $this->here = $this->product('TW-HERE', [$this->shop->id => 3, $this->depot->id => 1]);
        $this->away = $this->product('TW-AWAY', [$this->shop->id => 0, $this->depot->id => 5]);
    }

    public function test_the_header_opens_the_window_and_the_window_lists_the_towns_shops(): void
    {
        $this->get('http://localhost/')->assertOk()
            ->assertSee('id="torgash-location-dialog"', false)
            ->assertSee(route('locations.dialog'), false);

        $this->get(route('locations.dialog', ['place_id' => $this->kyiv->id]))->assertOk()
            ->assertSee('Kyivtown')->assertSee('Podil shop')->assertSee('Kontraktova sq. 1')
            ->assertDontSee('Stock at the chosen shop', false);

        app(SettingsManager::class)->set('torgash.warehouses', 'point_availability', 'choice');
        $this->get(route('locations.dialog', ['place_id' => $this->kyiv->id]))->assertSee('Stock at the chosen shop');

        $this->get(route('locations.dialog', ['place_id' => $this->lviv->id]))->assertOk()->assertDontSee('Podil shop');
    }

    /** The customer naming a town replaces the one checkout remembered; the shop and the switch go with it. */
    public function test_saving_names_the_town_and_the_shop(): void
    {
        app(SettingsManager::class)->set('torgash.warehouses', 'point_availability', 'choice');
        $this->withSession(['checkout_location' => ['place_id' => $this->lviv->id, 'town' => 'Lvivtown', 'postal' => null, 'country' => 'UA']]);

        $this->post(route('locations.save'), ['place_id' => $this->kyiv->id, 'point_id' => $this->shop->id, 'point_only' => '1'])->assertRedirect();

        $this->assertSame($this->kyiv->id, app(LocationStore::class)->placeId(), 'the explicit choice wins over the remembered town');
        $this->assertSame($this->shop->id, session('warehouses_point'));
        $this->assertTrue(session('warehouses_point_only'));

        $this->post(route('locations.save'), ['place_id' => $this->kyiv->id, 'point_id' => '0'])->assertRedirect();
        $this->assertNull(session('warehouses_point'), '"no shop" forgets it');
    }

    /** Only where the shop lets the customer decide does their switch narrow the catalogue — and then it says so. */
    public function test_the_switch_narrows_the_catalogue_and_the_notice_says_whose_stock_it_is(): void
    {
        $this->withSession(['warehouses_point' => $this->shop->id, 'warehouses_point_only' => true]);

        $page = $this->get('http://localhost/catalog')->assertOk();
        $page->assertSee('TW-AWAY')->assertDontSee('Stock is shown for the shop:', false);

        app(SettingsManager::class)->set('torgash.warehouses', 'point_availability', 'choice');
        $this->nextRequest();

        $page = $this->get('http://localhost/catalog')->assertOk();
        $page->assertSee('TW-HERE')->assertDontSee('TW-AWAY')->assertSee('Stock is shown for the shop:', false)->assertSee('Podil shop');
    }

    /** Not on the chosen shelf, but a warehouse can send it: a date, counted in working days. */
    public function test_a_product_elsewhere_gets_a_date(): void
    {
        $this->withSession(['warehouses_point' => $this->shop->id]);

        $page = $this->get('http://localhost/catalog')->assertOk();
        $page->assertSee('In stock at Podil shop');
        $page->assertSee('We will bring it by');

        $friday = CarbonImmutable::parse('2026-09-25');
        $this->assertSame('2026-09-29', app(Transfers::class)->date($this->away->id, $this->shop->id, $friday)?->toDateString(), 'Friday + 2 working days');

        app(SettingsManager::class)->set('torgash.warehouses', 'transfer_working_days', false);
        $this->assertSame('2026-09-27', app(Transfers::class)->date($this->away->id, $this->shop->id, $friday)?->toDateString());

        $this->depot->update(['transfer_days' => null]);
        app()->forgetInstance(Transfers::class);
        $this->assertNull(app(Transfers::class)->date($this->away->id, $this->shop->id), 'a warehouse that does not send offers no date');
    }

    public function test_gps_finds_the_nearest_town(): void
    {
        $this->getJson(route('places.nearest', ['lat' => 50.40, 'lng' => 30.60]))->assertOk()->assertJson(['id' => $this->kyiv->id]);
        $this->getJson(route('places.nearest', ['lat' => 10, 'lng' => 10]))->assertOk()->assertJson(['id' => null]);
    }

    protected function nextRequest(): void
    {
        app()->forgetInstance(StockAllocator::class);
        app()->forgetInstance(PointStore::class);
        app()->forgetInstance(Transfers::class);
    }

    /** @param  array<int, int>  $rows  warehouse id => qty */
    protected function product(string $sku, array $rows): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku),
            'price' => 10_00, 'stock' => array_sum($rows), 'status' => 'active', 'created_at' => now()->addYear(),
        ]);

        foreach ($rows as $warehouseId => $qty) {
            DB::table('m_warehouse_product_stock')->insert(['warehouse_id' => $warehouseId, 'product_id' => $product->id, 'qty' => $qty]);
        }

        return $product;
    }
}
