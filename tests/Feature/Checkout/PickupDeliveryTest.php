<?php

namespace Tests\Feature\Checkout;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Locations\Models\Place;
use Torgash\Shipping\Conditions\CartFacts;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Pickup\PickupProvider;
use Torgash\Shipping\ShippingOptions;
use Torgash\Shipping\ZoneMatcher;
use Torgash\Warehouses\Models\Warehouse;

/**
 * Collection from the shop's own points (PLAN §20.4/2).
 *
 * Three things have to hold, and each of them is silent when it breaks:
 *
 *   A point is offered only where it IS — a customer in Kyiv must not be shown
 *   the Lviv shop.
 *   A point that does not hold the whole order is not offered at all. Checking
 *   this while the list is built is what makes "collect it here" a promise the
 *   shop can keep; discovering it at deduction time would mean discovering it
 *   after the customer has paid.
 *   The order then comes off THAT point's shelf, whatever the priority order
 *   says — otherwise the customer arrives at a shop whose stock was quietly
 *   sold from elsewhere.
 */
class PickupDeliveryTest extends TestCase
{
    protected Place $kyiv;

    protected Place $lviv;

    protected ShippingMethod $method;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->kyiv = Place::create(['country' => 'UA', 'name' => ['en' => 'Kyiv'], 'type' => 'settlement', 'enabled' => true]);
        $this->lviv = Place::create(['country' => 'UA', 'name' => ['en' => 'Lviv'], 'type' => 'settlement', 'enabled' => true]);

        // Nothing else may hold stock: the seeded default warehouse would
        // otherwise be a second candidate in the tests about where units came
        // from, and the whole point is which warehouse won.
        Warehouse::query()->update(['enabled' => false, 'is_default' => false]);

        $this->method = ShippingMethod::create([
            'name' => ['en' => 'Pickup'], 'provider' => PickupProvider::KEY, 'price_mode' => 'carrier',
            'position' => 50, 'enabled' => true,
        ]);
        $this->method->rates()->create([
            'zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 0, 'enabled' => true,
        ]);

        $this->product = Product::create([
            'sku' => 'PICK-1', 'name' => ['en' => 'Pickable'], 'slug' => 'pickable-1',
            'price' => 10_00, 'stock' => 0, 'status' => 'active',
        ]);
    }

    public function test_only_the_points_in_the_customers_town_are_offered(): void
    {
        $kyivShop = $this->point('Kyiv shop', $this->kyiv, stock: 5);
        $this->point('Lviv shop', $this->lviv, stock: 5);

        $points = $this->pointsOffered($this->kyiv, [$this->product->id => 1]);

        $this->assertSame([$kyivShop], array_column($points, 'id'));
    }

    public function test_a_point_that_cannot_fill_the_whole_order_is_not_offered(): void
    {
        $small = $this->point('Corner shop', $this->kyiv, stock: 1);
        $big = $this->point('Flagship', $this->kyiv, stock: 9);

        // Two wanted, one on the shelf: the corner shop has some of the order,
        // which is not the same as having the order.
        $points = $this->pointsOffered($this->kyiv, [$this->product->id => 2]);

        $this->assertSame([$big], array_column($points, 'id'), 'a point that cannot fill the order was offered');
        $this->assertNotContains($small, array_column($points, 'id'));
    }

    public function test_with_nowhere_to_collect_the_method_is_not_offered_at_all(): void
    {
        // A warehouse in the right town that is not a collection point: a depot
        // that dispatches parcels is not somewhere a customer may turn up.
        $depot = $this->point('Depot', $this->kyiv, stock: 5);
        Warehouse::whereKey($depot)->update(['is_pickup' => false]);

        $this->assertSame([], $this->pickupOptions($this->kyiv)->all());
    }

    public function test_the_order_is_taken_from_the_point_it_will_be_collected_from(): void
    {
        // Priority says the warehouse first — and it holds everything, so
        // consolidation would happily take it all from there.
        $warehouse = $this->point('Main', $this->kyiv, stock: 5, sort: 0);
        Warehouse::whereKey($warehouse)->update(['is_pickup' => false, 'is_public' => false]);
        $shop = $this->point('Kyiv shop', $this->kyiv, stock: 5, sort: 10);

        $this->seedCart(2);

        $this->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Kyiv',
            'place_id' => $this->kyiv->id,
            'country' => 'UA',
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
            'shipping_method_id' => $this->method->id.':'.PickupProvider::KEY,
            'shipping_fields' => ['pickup_point' => $shop],
        ])->assertRedirect();

        $order = Order::latest('id')->firstOrFail();

        // Frozen onto the order: the id the stock rule acts on, and the name
        // and address the customer is shown afterwards.
        $this->assertSame(PickupProvider::KEY, $order->shipping_details['carrier']);
        $this->assertSame($shop, $order->shipping_details['pickup_point_id']);
        $this->assertSame('Kyiv shop', $order->shipping_details['pickup_point']);

        // And the units left the shop's shelf, not the higher-priority warehouse.
        $this->assertSame([$shop => -2], $this->breakdown($order));
        $this->assertSame(5, $this->stockAt($warehouse));
        $this->assertSame(3, $this->stockAt($shop));
    }

    /**
     * A collected order has no street to be taken to. The field is relaxed for
     * the chosen OPTION, not for the shop: the same shop still demands an
     * address from everyone it delivers to.
     */
    public function test_a_collected_order_needs_no_street_address(): void
    {
        $shop = $this->point('Kyiv shop', $this->kyiv, stock: 5);
        $this->seedCart(1);

        $this->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'city' => 'Kyiv',
            'place_id' => $this->kyiv->id,
            'country' => 'UA',
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
            'shipping_method_id' => $this->method->id.':'.PickupProvider::KEY,
            'shipping_fields' => ['pickup_point' => $shop],
        ])->assertRedirect();

        $this->assertSame(1, Order::count());
    }

    public function test_a_delivered_order_still_needs_one(): void
    {
        $this->point('Kyiv shop', $this->kyiv, stock: 5);
        $this->seedCart(1);

        // The seeded flat-rate courier method: nothing about it says it is
        // collected, so the address is required as it always was.
        $courier = ShippingMethod::whereNull('provider')->where('enabled', true)->orderBy('position')->firstOrFail();

        $this->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'city' => 'Kyiv',
            'country' => 'UA',
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
            'shipping_method_id' => (string) $courier->id,
        ])->assertSessionHasErrors('address_line');

        $this->assertSame(0, Order::count());
    }

    public function test_an_order_with_no_point_chosen_is_refused(): void
    {
        $this->point('Kyiv shop', $this->kyiv, stock: 5);
        $this->seedCart(1);

        $this->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Kyiv',
            'place_id' => $this->kyiv->id,
            'country' => 'UA',
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
            'shipping_method_id' => $this->method->id.':'.PickupProvider::KEY,
        ])->assertSessionHasErrors('shipping_fields');

        $this->assertSame(0, Order::count());
    }

    /** A pickup point holding this product, and its id. */
    protected function point(string $name, Place $place, int $stock, int $sort = 0): int
    {
        $warehouse = Warehouse::create([
            'name' => ['en' => $name], 'place_id' => $place->id, 'sort' => $sort,
            'enabled' => true, 'is_public' => true, 'is_pickup' => true,
        ]);

        DB::table('m_warehouse_product_stock')->insert([
            'warehouse_id' => $warehouse->id, 'product_id' => $this->product->id, 'qty' => $stock,
        ]);

        DB::table('m_catalog_products')->where('id', $this->product->id)->update([
            'stock' => DB::table('m_warehouse_product_stock')->where('product_id', $this->product->id)->sum('qty'),
        ]);

        return $warehouse->id;
    }

    /** @param array<int,int> $lines */
    protected function pointsOffered(Place $place, array $lines): array
    {
        return (array) hook_filter('shipping.pickup_points', [], [
            'place_id' => $place->id,
            'lines' => $lines,
        ]);
    }

    /** What the quote offers for this place — the pickup method only. */
    protected function pickupOptions(Place $place): \Illuminate\Support\Collection
    {
        return app(ShippingOptions::class)
            ->for(
                new Location(country: 'UA', placeId: $place->id),
                new Package(subtotal: 10_00),
                new CartFacts(known: true, subtotal: 10_00, quantities: [$this->product->id => 1]),
            )
            ->where('provider', PickupProvider::KEY)
            ->values();
    }

    protected function seedCart(int $qty): void
    {
        $this->post('http://localhost/cart/add/pickable-1', ['qty' => $qty])->assertOk();

        // The basket refuses what the shelf does not hold — a refusal is also
        // a 200, so say plainly that the goods actually went in.
        $this->assertSame($qty, app(\Torgash\Cart\CartManager::class)->count(), 'the basket took the goods');
    }

    /** warehouse_id => delta, as the order line recorded it. */
    protected function breakdown(Order $order): array
    {
        $line = collect($order->items)->firstWhere('product_id', $this->product->id);

        return array_map('intval', (array) ($line['warehouses'] ?? []));
    }

    protected function stockAt(int $warehouseId): int
    {
        return (int) DB::table('m_warehouse_product_stock')
            ->where('warehouse_id', $warehouseId)->where('product_id', $this->product->id)->value('qty');
    }
}
