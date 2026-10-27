<?php

namespace Tests\Feature\Cart;

use App\Models\User;
use Tests\TestCase;
use Torgash\Cart\CartManager;
use Torgash\Cart\Models\Cart;
use Torgash\Catalog\Models\Product;

/**
 * The cart is a row addressed by a token, and the session holds only the token.
 *
 * Everything that used to work has to keep working — the browser flow is
 * unchanged from the outside — while two new things become possible: a client
 * with no session can hold the same cart, and a cart nobody came back to is
 * still there to be counted.
 *
 * The rule worth guarding hardest is the one that is easy to break by accident:
 * READING a cart must not create one. `count()` runs on every page for the
 * header badge, and a row per crawler is not a cart, it is a table that grows
 * for ever.
 */
class CartTokenTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'CART-1', 'name' => ['en' => 'Cartable'], 'slug' => 'cartable',
            'price' => 10_00, 'stock' => 10, 'status' => 'active',
        ]);
    }

    public function test_looking_at_an_empty_cart_creates_nothing(): void
    {
        $cart = app(CartManager::class);

        $this->assertSame([], $cart->raw());
        $this->assertSame(0, $cart->count());
        $this->assertSame(0, Cart::count());
    }

    public function test_adding_creates_one_row_and_the_session_keeps_only_its_token(): void
    {
        $this->post('http://localhost/cart/add/cartable', ['qty' => 2])->assertOk();

        $this->assertSame(1, Cart::count());

        $row = Cart::firstOrFail();
        // One line, two of it. A line is a record rather than a number since
        // products can be configured (PLAN §33.3); for a plain product its key
        // is still the product id. Field by field, not as one array: these
        // records go through jsonb, which does not keep key order.
        $this->assertCount(1, $row->items);
        $this->assertSame((string) $this->product->id, $row->items[0]['key']);
        $this->assertSame($this->product->id, $row->items[0]['product_id']);
        $this->assertSame(2, $row->items[0]['qty']);
        $this->assertSame($row->token, session('cart_token'));
        // The contents are not in the session any more — only the address.
        $this->assertEmpty(session('cart'));
    }

    /** A client with no session says which cart it means. */
    public function test_a_token_addresses_the_same_cart_without_a_session(): void
    {
        $cart = app(CartManager::class);
        $cart->add($this->product->id, 3);
        $token = $cart->token();

        // A different manager, told only the token.
        $other = (new CartManager)->useToken($token);

        $this->assertSame(3, $other->count());
        $this->assertSame(3000, $other->total());
    }

    public function test_an_unknown_token_is_an_empty_cart_and_not_an_error(): void
    {
        $cart = (new CartManager)->useToken('nobody-has-this-token');

        $this->assertSame([], $cart->raw());
        $this->assertSame(0, Cart::count());
    }

    /** A cart built before the table existed is carried over, not lost. */
    public function test_a_session_cart_from_before_is_migrated_on_first_touch(): void
    {
        session(['cart' => [$this->product->id => 4], 'cart_coupon' => 'WELCOME']);

        $cart = app(CartManager::class);

        $this->assertSame(4, $cart->count());
        $this->assertSame('WELCOME', $cart->couponCode());
        $this->assertSame(1, Cart::count());
        // ...and the old keys are gone, so it cannot be migrated twice.
        $this->assertEmpty(session('cart'));
    }

    /** Emptying keeps the row: the cart of an order placed a second ago is the shop's most interesting one. */
    public function test_clearing_empties_the_cart_without_deleting_it(): void
    {
        $cart = app(CartManager::class);
        $cart->add($this->product->id, 1);
        $cart->setCouponCode('SALE');

        $cart->clear();

        $this->assertSame(0, $cart->count());
        $this->assertNull($cart->couponCode());
        $this->assertSame(1, Cart::count());
    }

    /** Signing in stamps the cart, which is what will let it follow somebody. */
    public function test_a_signed_in_visitors_cart_knows_whose_it_is(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        app(CartManager::class)->add($this->product->id, 1);

        $this->assertSame($user->id, Cart::firstOrFail()->user_id);
    }

    /** Every write is a touch, which is what the abandoned sweep counts from. */
    public function test_a_write_marks_the_cart_as_alive(): void
    {
        $cart = app(CartManager::class);
        $cart->add($this->product->id, 1);

        $first = Cart::firstOrFail()->last_active_at;
        $this->assertNotNull($first);

        $this->travel(2)->days();
        $cart->update($this->product->id, 2);

        $this->assertTrue(Cart::firstOrFail()->last_active_at->greaterThan($first));
    }
}
