<?php

namespace Tests\Feature\Catalog;

use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;

/**
 * A product's breadcrumbs are ONE path — the deepest category it is listed in,
 * with that category's ancestors before it — never every category it is filed
 * under side by side.
 */
class ProductTrailTest extends TestCase
{
    public function test_the_trail_is_the_deepest_category_with_its_ancestors(): void
    {
        $root = Category::create(['name' => ['en' => 'PT Networking'], 'slug' => 'pt-networking', 'status' => 'active']);
        $leaf = Category::create(['parent_id' => $root->id, 'name' => ['en' => 'PT Gateways'], 'slug' => 'pt-gateways', 'status' => 'active']);
        $other = Category::create(['name' => ['en' => 'PT Sale'], 'slug' => 'pt-sale', 'status' => 'active']);

        $product = Product::create(['sku' => 'PT-1', 'name' => ['en' => 'PT Gateway'], 'slug' => 'pt-gateway', 'price' => 100_00, 'stock' => 1, 'status' => 'active']);
        $product->categories()->sync([$other->id, $leaf->id]);

        $html = $this->get('http://localhost/product/pt-gateway')->assertOk()->getContent();
        $crumbs = substr($html, (int) strpos($html, 'breadcrumbs'), 3000);

        $this->assertMatchesRegularExpression('/PT Networking.*PT Gateways.*PT Gateway/s', $crumbs);
        $this->assertStringNotContainsString('PT Sale', $crumbs, 'one path, not every category');
    }
}
