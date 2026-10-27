<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use Torgash\Cart\Models\Cart;
use Torgash\Catalog\Models\Product;

/**
 * The shop half: what a visitor may see, and the cart they are building.
 *
 * Public, like the storefront's own pages — a mobile app shipping with an
 * embedded token is not shipping with a secret. What guards a CART is its
 * token, which is the only thing here anybody could want to protect.
 *
 * Two things are pinned hardest, and both are about the line between the halves:
 * the shop section cannot reach an admin resource however the request is
 * shaped, and it cannot write to one — its routes are GET, so "read-only" is a
 * fact about the route table rather than a check somebody could forget.
 */
class ShopApiTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'SHOP-1', 'name' => ['en' => 'Shoppable'], 'slug' => 'shoppable',
            'price' => 25_00, 'stock' => 5, 'status' => 'active',
        ]);
    }

    // ------------------------------------------------------------- catalogue

    public function test_the_catalogue_reads_without_a_token(): void
    {
        $this->getJson('http://localhost/api/v2/shop/products')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'SHOP-1')
            // A visitor's view: no cost price anywhere in it.
            ->assertJsonMissingPath('data.0.cost_price');
    }

    public function test_a_disabled_product_is_not_in_the_shop_list(): void
    {
        Product::create([
            'sku' => 'HIDDEN-1', 'name' => ['en' => 'Not for sale'], 'slug' => 'not-for-sale',
            'price' => 10_00, 'stock' => 1, 'status' => 'disabled',
        ]);

        $skus = collect($this->getJson('http://localhost/api/v2/shop/products?itemsPerPage=200')->json('data'))
            ->pluck('sku');

        $this->assertContains('SHOP-1', $skus);
        $this->assertNotContains('HIDDEN-1', $skus);
    }

    /** The section comes from the URL: the shop half cannot reach the admin one. */
    public function test_the_shop_section_cannot_read_an_admin_resource(): void
    {
        $this->getJson('http://localhost/api/v2/shop/orders')->assertStatus(404);
    }

    /** ...and cannot write to anything at all: its routes are GET. */
    public function test_the_shop_section_has_no_write_routes(): void
    {
        $this->patchJson('http://localhost/api/v2/shop/products/SHOP-1', ['stock' => 999])
            ->assertStatus(405);

        $this->assertSame(5, $this->product->fresh()->stock);
    }

    // ------------------------------------------------------------------ cart

    public function test_a_cart_is_created_empty_and_answers_to_its_token(): void
    {
        $created = $this->postJson('http://localhost/api/v2/shop/carts')->assertStatus(201);

        $token = $created->json('data.token');

        $this->assertNotEmpty($token);
        $this->assertSame(0, $created->json('data.count'));

        $this->getJson('http://localhost/api/v2/shop/carts/'.$token)
            ->assertOk()
            ->assertJsonPath('data.token', $token);
    }

    /** One verb for add, change and remove: "how many of this do you want". */
    public function test_one_call_sets_the_quantity_and_zero_removes_the_line(): void
    {
        $token = $this->postJson('http://localhost/api/v2/shop/carts')->json('data.token');
        $url = 'http://localhost/api/v2/shop/carts/'.$token.'/items';

        $this->patchJson($url, ['product' => 'SHOP-1', 'quantity' => 3])
            ->assertOk()
            ->assertJsonPath('data.count', 3)
            ->assertJsonPath('data.total', 7500);

        $this->patchJson($url, ['product' => 'SHOP-1', 'quantity' => 1])
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->patchJson($url, ['product' => 'SHOP-1', 'quantity' => 0])
            ->assertOk()
            ->assertJsonPath('data.count', 0)
            ->assertJsonCount(0, 'data.items');
    }

    /** Addressed like everything else: id, sku or the caller's own key. */
    public function test_a_product_can_be_named_by_any_of_its_keys(): void
    {
        $this->product->update(['external_id' => 'erp-9']);
        $token = $this->postJson('http://localhost/api/v2/shop/carts')->json('data.token');

        foreach ([(string) $this->product->id, 'SHOP-1', 'erp-9'] as $key) {
            $this->patchJson('http://localhost/api/v2/shop/carts/'.$token.'/items', [
                'product' => $key, 'quantity' => 1,
            ])->assertOk()->assertJsonPath('data.count', 1);
        }
    }

    /** A cart nobody gave you the address of does not exist as far as you know. */
    public function test_an_unknown_cart_is_a_404_and_not_a_403(): void
    {
        $this->getJson('http://localhost/api/v2/shop/carts/not-a-real-token')->assertStatus(404);

        $this->patchJson('http://localhost/api/v2/shop/carts/not-a-real-token/items', [
            'product' => 'SHOP-1', 'quantity' => 1,
        ])->assertStatus(404);
    }

    public function test_clearing_empties_the_cart_but_keeps_it_addressable(): void
    {
        $token = $this->postJson('http://localhost/api/v2/shop/carts')->json('data.token');
        $this->patchJson('http://localhost/api/v2/shop/carts/'.$token.'/items', ['product' => 'SHOP-1', 'quantity' => 2]);

        $this->deleteJson('http://localhost/api/v2/shop/carts/'.$token)
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        $this->assertSame(1, Cart::where('token', $token)->count());
    }

    /** The API prices exactly as the storefront does — same manager, same rules. */
    public function test_prices_come_from_the_shops_own_pricing(): void
    {
        $token = $this->postJson('http://localhost/api/v2/shop/carts')->json('data.token');

        $body = $this->patchJson('http://localhost/api/v2/shop/carts/'.$token.'/items', [
            'product' => 'SHOP-1', 'quantity' => 2,
        ])->json('data');

        $this->assertSame(2500, $body['items'][0]['unit_price']);
        $this->assertSame(5000, $body['items'][0]['line_total']);
        $this->assertSame(5000, $body['total']);
    }
}
