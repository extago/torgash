<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Torgash\Cart\Models\Cart;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\ZoneMatcher;

/**
 * An order a manager places from the admin (PLAN §63.8): the same PlaceOrder
 * the checkout uses, fed by a throwaway basket; the manager's prices on the
 * lines; the customer's account, not the manager's, on the row.
 */
class ManagerOrderTest extends TestCase
{
    protected User $admin;

    protected Product $product;

    protected Product $other;

    protected PaymentMethod $payment;

    protected ShippingMethod $shipping;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::role('admin')->firstOrFail();

        $this->product = Product::create([
            'sku' => 'MGR-1', 'name' => ['en' => 'Managed widget'], 'slug' => 'managed-widget',
            'price' => 50_00, 'stock' => 8, 'status' => 'active',
        ]);
        $this->other = Product::create([
            'sku' => 'MGR-2', 'name' => ['en' => 'Other managed widget'], 'slug' => 'other-managed-widget',
            'price' => 10_00, 'stock' => 3, 'status' => 'active',
        ]);

        $this->shipping = ShippingMethod::create([
            'name' => ['en' => 'Courier by arrangement'], 'price_mode' => 'bands', 'position' => 90, 'enabled' => true,
        ]);
        $this->shipping->rates()->create([
            'zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 7_00, 'enabled' => true,
        ]);
        $this->payment = PaymentMethod::create([
            'name' => ['en' => 'Cash to the courier'], 'enabled' => true, 'position' => 90,
        ]);
    }

    public function test_the_page_opens_and_its_helpers_answer(): void
    {
        $customer = User::create(['name' => 'Olena Buyer', 'email' => 'olena@example.test', 'password' => 'secret123']);
        $customer->addresses()->create([
            'full_name' => 'Olena Buyer', 'phone' => '+380931234567', 'address_line' => '5 Lane', 'city' => 'Lviv', 'is_default' => true,
        ]);

        $this->actingAs($this->admin)->get('http://localhost/admin/orders/create')
            ->assertOk()->assertSee('Cash to the courier');

        $this->actingAs($this->admin)->get('http://localhost/admin/orders/create/customers?q=olena')
            ->assertOk()->assertSee('olena@example.test')->assertSee('Lviv');

        $this->actingAs($this->admin)->get('http://localhost/admin/orders/create/products?q=MGR-2')
            ->assertOk()->assertSee('Other managed widget')->assertDontSee('Managed widget<');

        $this->actingAs($this->admin)->post('http://localhost/admin/orders/create/delivery', [
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            'country' => 'UA', 'city' => 'Kyiv',
        ])->assertOk()->assertSee('Courier by arrangement')->assertSee('name="shipping_method_id"', false);

        // The throwaway basket of a quote does not outlive it.
        $this->assertSame(0, Cart::count());
    }

    public function test_an_order_is_placed_with_the_managers_prices_for_the_customer(): void
    {
        $customer = User::create(['name' => 'Olena Buyer', 'email' => 'olena@example.test', 'password' => 'secret123']);
        $offer = $this->offerId();

        $response = $this->actingAs($this->admin)->post('http://localhost/admin/orders', [
            'user_id' => $customer->id,
            'customer_name' => 'Olena Buyer',
            'email' => 'olena@example.test',
            'phone' => '+380931234567',
            'country' => 'UA', 'city' => 'Kyiv', 'address_line' => '1 Khreshchatyk', 'postal_code' => '01001',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => '45'],
                ['product_id' => $this->other->id, 'qty' => 1, 'unit_price' => ''],
            ],
            'shipping_method_id' => $offer,
            'payment_method_id' => $this->payment->id,
            'notes' => 'Rang on Monday.',
        ]);

        $order = Order::latest('id')->firstOrFail();
        $response->assertRedirect(route('admin.orders.show', $order));

        $this->assertSame($customer->id, $order->user_id, 'the order is the customer\'s, not the manager\'s');
        $this->assertSame('Olena Buyer', $order->customer_name);
        $this->assertSame('Rang on Monday.', $order->notes);

        $lines = collect($order->items)->keyBy('product_id');
        $this->assertSame([2, 45_00, 90_00], [$lines[$this->product->id]['qty'], $lines[$this->product->id]['unit_price'], $lines[$this->product->id]['line_total']]);
        $this->assertSame(10_00, $lines[$this->other->id]['unit_price'], 'an empty price box is the list price');
        $this->assertSame(100_00, $order->subtotal);
        $this->assertSame(7_00, $order->shipping_cost);
        $this->assertSame(107_00, $order->total);

        $this->assertSame(6, $this->product->fresh()->stock, 'two left the shelf');
        $this->assertTrue(OrderLog::where('order_id', $order->id)->where('description', 'Created from the admin by :manager')->exists());
        $this->assertSame(0, Cart::count(), 'the throwaway basket is gone');
    }

    public function test_an_order_without_goods_is_refused(): void
    {
        $this->actingAs($this->admin)->post('http://localhost/admin/orders', [
            'customer_name' => 'Nobody', 'email' => 'nobody@example.test',
            'country' => 'UA', 'city' => 'Kyiv', 'address_line' => '1 Lane',
            'payment_method_id' => $this->payment->id,
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, Order::count());
    }

    public function test_a_manager_without_the_permission_is_kept_out(): void
    {
        $clerk = User::create(['name' => 'Clerk', 'email' => 'clerk@torgash.local', 'password' => 'secret123']);

        $this->actingAs($clerk)->get('http://localhost/admin/orders/create')->assertForbidden();
    }

    /** A town picked from the registry travels as its place id — what zones and carriers match on. */
    public function test_a_town_from_the_registry_names_the_place_on_the_order(): void
    {
        $this->actingAs($this->admin)->post('http://localhost/admin/orders/create/delivery', [
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            'place_id' => 703448, 'city' => 'Kyiv',
        ])->assertOk()->assertSee('Courier by arrangement');

        $this->actingAs($this->admin)->post('http://localhost/admin/orders', [
            'customer_name' => 'Olena Buyer', 'email' => 'olena2@example.test',
            'place_id' => 703448, 'city' => 'Kyiv', 'address_line' => '1 Khreshchatyk', 'country' => 'UA',
            'lines' => [['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => '']],
            'shipping_method_id' => $this->offerId(['place_id' => 703448, 'city' => 'Kyiv']),
            'payment_method_id' => $this->payment->id,
        ])->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame([703448, 'UA', 'Kyiv'], [(int) $order->place_id, $order->country, $order->city]);
    }

    /** The id of OUR delivery among those offered a Kyiv address (the seeded shop has its own), as the form would post it. */
    protected function offerId(array $address = ['country' => 'UA', 'city' => 'Kyiv']): string
    {
        $html = $this->actingAs($this->admin)->post('http://localhost/admin/orders/create/delivery', [
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
        ] + $address)->assertOk()->getContent();

        preg_match('/name="shipping_method_id" value="([^"]+)"[^>]*>\s*<span class="grow">\s*Courier by arrangement/s', $html, $m);

        return $m[1] ?? '';
    }
}
