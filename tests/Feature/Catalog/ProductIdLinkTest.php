<?php

namespace Tests\Feature\Catalog;

use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * `/p/{id}` — a product by its id, for whoever holds the id and not the slug
 * (a Telegram message, a 1C document): sent on to the product's own address,
 * so there is still one address a search engine knows; a product that is
 * gone answers 404.
 */
class ProductIdLinkTest extends TestCase
{
    public function test_the_id_sends_on_to_the_products_own_address(): void
    {
        $product = Product::create(['sku' => 'PID-1', 'name' => ['en' => 'Lamp'], 'slug' => 'pid-lamp', 'price' => 10_00, 'stock' => 3, 'status' => 'active']);

        $this->get('http://localhost/p/'.$product->id)
            ->assertStatus(301)->assertRedirect('http://localhost/product/pid-lamp');

        $this->get('http://localhost/p/'.($product->id + 100000))->assertNotFound();
        $this->get('http://localhost/p/pid-lamp')->assertNotFound();

        $product->delete();
        $this->get('http://localhost/p/'.$product->id)->assertNotFound();
    }
}
