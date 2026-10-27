<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Cart\Models\Cart;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * Checkout over the API — the same shop, asked one question at a time.
 *
 * A browser answers everything on one page; a client has to give an address
 * before it can be told what delivery costs. So the answers live on the cart
 * and `complete` places the order from them.
 *
 * The line these tests hold is that the API decides NOTHING of its own. It
 * cannot buy a delivery option the storefront would not have offered, cannot
 * skip a required field, and places the order through `PlaceOrder` — the same
 * object the form goes through, which is why stock, statuses, currencies and
 * payment methods behave identically here.
 */
class ShopCheckoutTest extends TestCase
{
    protected Product $product;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->product = Product::create([
            'sku' => 'CHK-1', 'name' => ['en' => 'Checkoutable'], 'slug' => 'checkoutable',
            'price' => 20_00, 'stock' => 10, 'status' => 'active',
        ]);

        $this->token = $this->postJson('http://localhost/api/v2/shop/carts')->json('data.token');

        $this->patchJson($this->url('/items'), ['product' => 'CHK-1', 'quantity' => 2])->assertOk();
    }

    protected function url(string $suffix = ''): string
    {
        return 'http://localhost/api/v2/shop/carts/'.$this->token.$suffix;
    }

    /** The answers accumulate; each step does not unanswer the ones before it. */
    public function test_answers_are_merged_step_by_step(): void
    {
        $this->patchJson($this->url('/checkout'), ['email' => 'buyer@example.test'])->assertOk();
        $this->patchJson($this->url('/checkout'), ['customer_name' => 'Guest Buyer'])->assertOk();

        $answers = $this->getJson($this->url('/checkout'))->assertOk()->json('data.answers');

        $this->assertSame('buyer@example.test', $answers['email']);
        $this->assertSame('Guest Buyer', $answers['customer_name']);
    }

    /** What is on offer is priced for the answers so far, by the shipping module. */
    public function test_the_delivery_options_are_the_storefronts_own(): void
    {
        $body = $this->getJson($this->url('/checkout'))->assertOk()->json('data');

        $ids = collect($body['delivery'])->pluck('id');
        $offered = ShippingMethod::whereNull('provider')->where('enabled', true)->pluck('id')->map(fn ($id) => (string) $id);

        $this->assertNotEmpty($offered, 'the fixture shop has no delivery at all — the rest of this asserts nothing');
        $this->assertTrue($offered->every(fn ($id) => $ids->contains($id)));
        // Payment methods come from the same list the checkout screen draws.
        $this->assertNotEmpty($body['payment']);
    }

    /** An incomplete checkout says what is missing rather than placing half an order. */
    public function test_completing_too_early_names_the_missing_fields(): void
    {
        $response = $this->postJson($this->url('/checkout/complete'))->assertStatus(422);

        $this->assertArrayHasKey('customer_name', $response->json('errors'));
        $this->assertArrayHasKey('email', $response->json('errors'));
        $this->assertSame(0, Order::count());
    }

    /** A delivery option the storefront never offered cannot be bought. */
    public function test_an_option_that_is_not_on_offer_is_refused(): void
    {
        $this->patchJson($this->url('/checkout'), $this->answers(['shipping_method_id' => '999999']))->assertOk();

        $this->postJson($this->url('/checkout/complete'))
            ->assertStatus(422)
            ->assertJsonPath('errors.shipping_method_id.0', fn ($message) => is_string($message));

        $this->assertSame(0, Order::count());
    }

    /** The whole way through, and the order is the shop's own in every respect. */
    public function test_a_completed_checkout_places_a_real_order(): void
    {
        $this->patchJson($this->url('/checkout'), $this->answers())->assertOk();

        $response = $this->postJson($this->url('/checkout/complete'))->assertStatus(201);

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame($order->order_number, $response->json('data.order.number'));
        $this->assertSame(2, collect($order->items)->sum('qty'));
        // The answers were frozen onto the order, not merely validated.
        $this->assertSame('Guest Buyer', $order->customer_name);
        $this->assertSame('guest@example.test', $order->email);
        // The order keeps the delivery's NAME as it read at the time, not a
        // pointer to a method a shop may rename or delete tomorrow.
        $this->assertNotNull($order->shipping_method_name);
        $this->assertSame(4000 + $order->shipping_cost, $order->total);
        // Placed by PlaceOrder, so the stock moved exactly as it does on the site.
        $this->assertSame(8, $this->product->fresh()->stock);
        // ...and the cart it came from is empty, not deleted.
        $this->assertSame(0, $this->getJson($this->url())->json('data.count'));
        $this->assertSame(1, Cart::where('token', $this->token)->count());
    }

    /** An empty cart is not a checkout. */
    public function test_an_empty_cart_cannot_be_completed(): void
    {
        $this->deleteJson($this->url())->assertOk();

        $this->postJson($this->url('/checkout/complete'))->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    /** @param array<string, mixed> $overrides */
    protected function answers(array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->orderBy('position')->value('id'),
        ];
    }
}
