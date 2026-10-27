<?php

namespace Tests\Feature\Cart;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Cart\CartManager;
use Torgash\Cart\Models\Cart;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * Nothing is sold that is not there to sell.
 *
 * The buy box always capped its stepper at the shelf and hid the button of a
 * product that could not be ordered — in the browser. The server took
 * whatever it was sent: a product with nothing left went into a basket fifty
 * at a time, and the checkout placed it, taking the warehouse below zero. The
 * ordinary way to get there needed no tampering at all: a product sells out
 * while it waits in somebody else's basket.
 *
 * One rule, `Product::orderLimit()`, asked by the basket at every door and by
 * the checkout once more with the products' rows locked.
 */
class OrderLimitTest extends TestCase
{
    public function test_a_product_that_cannot_be_ordered_stays_out_of_the_basket(): void
    {
        $product = $this->product('LIM-OUT', stock: 0);

        $this->post('http://localhost/cart/add/'.$product->slug, ['qty' => 50], $this->fromBuyBox($product))
            ->assertOk()
            ->assertSee('is not available to order right now', false);

        $this->assertSame(0, app(CartManager::class)->count());
    }

    public function test_the_basket_does_not_grow_past_the_shelf(): void
    {
        $product = $this->product('LIM-3', stock: 3);

        $this->post('http://localhost/cart/add/'.$product->slug, ['qty' => 2], $this->fromBuyBox($product))->assertOk();
        $this->post('http://localhost/cart/add/'.$product->slug, ['qty' => 2], $this->fromBuyBox($product))
            ->assertOk()
            ->assertSee('are left in stock', false);

        $this->assertSame(2, app(CartManager::class)->count());
    }

    public function test_a_preorder_is_not_capped_by_the_shelf(): void
    {
        $product = $this->product('LIM-PRE', stock: 0, availability: 'preorder');

        $this->post('http://localhost/cart/add/'.$product->slug, ['qty' => 5], $this->fromBuyBox($product))->assertOk();

        $this->assertSame(5, app(CartManager::class)->count());
    }

    public function test_a_disabled_card_is_not_for_sale_whatever_its_shelf_says(): void
    {
        $product = $this->product('LIM-OFF', stock: 10, status: Product::STATUS_DISABLED);

        $this->post('http://localhost/cart/add/'.$product->slug, ['qty' => 1], $this->fromBuyBox($product))
            ->assertSee('is not available to order right now', false);

        $this->assertSame(0, app(CartManager::class)->count());
    }

    /** A card's button has no buy box to answer into: nothing changes, a notice says why. */
    public function test_a_refusal_from_a_list_is_said_in_a_corner_notice(): void
    {
        $product = $this->product('LIM-LIST', stock: 0);

        $this->post('http://localhost/cart/add/'.$product->slug, ['qty' => 1], ['HX-Request' => 'true'])
            ->assertOk()
            ->assertDontSee('id="cart-badge"', false)
            ->assertSee('Not added to your basket');
    }

    public function test_a_quantity_typed_past_the_shelf_on_the_cart_page_becomes_the_shelf(): void
    {
        $product = $this->product('LIM-TYPE', stock: 3);
        $this->post('http://localhost/cart/add/'.$product->slug, ['qty' => 1])->assertOk();

        $this->patch('http://localhost/cart/items/'.$product->id, ['qty' => 10], ['HX-Request' => 'true'])->assertOk();

        $this->assertSame(3, app(CartManager::class)->count());
    }

    /** The shelf emptied while the basket waited: the line says so, and stepping down still works. */
    public function test_a_basket_left_holding_more_than_the_shelf_is_marked_and_can_step_down(): void
    {
        $product = $this->product('LIM-WAIT', stock: 5);
        $this->post('http://localhost/cart/add/'.$product->slug, ['qty' => 4])->assertOk();

        $product->forceFill(['stock' => 2])->saveQuietly();

        $this->get('http://localhost/cart')->assertSee('Only 2 left in stock — lower the quantity to place the order.');

        $this->patch('http://localhost/cart/items/'.$product->id, ['qty' => 4, 'step' => -1], ['HX-Request' => 'true'])->assertOk();

        $this->assertSame(2, app(CartManager::class)->count());
    }

    public function test_the_checkout_refuses_a_basket_the_shelf_can_no_longer_cover(): void
    {
        Mail::fake();
        $product = $this->product('LIM-PLACE', stock: 5);
        $this->post('http://localhost/cart/add/'.$product->slug, ['qty' => 4])->assertOk();

        // Somebody else bought three in the meantime.
        $product->forceFill(['stock' => 2])->saveQuietly();

        $this->post('http://localhost/checkout', $this->form())->assertSessionHasErrors('cart');

        $this->assertSame(0, Order::count());
        $this->assertSame(2, $product->fresh()->stock);
    }

    public function test_the_checkout_refuses_a_product_that_stopped_being_sold(): void
    {
        Mail::fake();
        $product = $this->product('LIM-GONE', stock: 5);
        $this->post('http://localhost/cart/add/'.$product->slug, ['qty' => 1])->assertOk();

        $product->forceFill(['availability_status' => 'discontinued'])->saveQuietly();

        $this->post('http://localhost/checkout', $this->form())->assertSessionHasErrors('cart');

        $this->assertSame(0, Order::count());
    }

    public function test_the_last_units_can_still_be_bought(): void
    {
        Mail::fake();
        $product = $this->product('LIM-LAST', stock: 2);
        $this->post('http://localhost/cart/add/'.$product->slug, ['qty' => 2])->assertOk();

        $this->post('http://localhost/checkout', $this->form())->assertSessionHasNoErrors();

        $this->assertSame(1, Order::count());
        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_the_api_refuses_more_than_the_shelf(): void
    {
        $product = $this->product('LIM-API', stock: 3);
        $token = $this->postJson('http://localhost/api/v2/shop/carts')->assertCreated()->json('data.token');

        $this->patchJson("http://localhost/api/v2/shop/carts/{$token}/items", ['product' => 'LIM-API', 'quantity' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product');

        $this->patchJson("http://localhost/api/v2/shop/carts/{$token}/items", ['product' => 'LIM-API', 'quantity' => 3])
            ->assertOk()
            ->assertJsonPath('data.count', 3);
    }

    /** An exchange a manager arranges is the manager's decision, not held to the shelf. */
    public function test_a_cart_filled_on_somebodys_behalf_is_not_held_to_the_shelf(): void
    {
        $product = $this->product('LIM-BEHALF', stock: 0);
        $row = Cart::create(['token' => Cart::newToken(), 'items' => [], 'last_active_at' => now()]);

        $cart = (new CartManager)->useToken($row->token)->onBehalf();
        $cart->add($product->id, 2);

        $this->assertSame(2, $cart->count());
        $this->assertNull($cart->items()->first()->problem);
    }

    // ---------------------------------------------------------------- helpers

    protected function product(string $sku, int $stock, string $availability = 'in_stock', string $status = Product::STATUS_ACTIVE): Product
    {
        return Product::create([
            'sku' => $sku,
            'name' => ['en' => 'Product '.$sku],
            'slug' => strtolower($sku),
            'price' => 10_00,
            'stock' => $stock,
            'availability_status' => $availability,
            'status' => $status,
        ]);
    }

    /** @return array<string, string> the headers the product page's buy box sends */
    protected function fromBuyBox(Product $product): array
    {
        return ['HX-Request' => 'true', 'HX-Trigger' => 'buy-box-'.$product->id.'-form'];
    }

    /** @return array<string, mixed> */
    protected function form(): array
    {
        return [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ];
    }
}
