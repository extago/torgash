<?php

namespace Tests\Feature\Catalog;

use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;

/**
 * A listing's page past its last one is not found (audit 2026-10-15:
 * `?page=999` answered 200 with an empty grid — a "soft 404" a crawler keeps
 * as a page). Page one of an empty list is still a page.
 */
class PastLastPageTest extends TestCase
{
    public function test_a_page_past_the_last_one_is_not_found_and_page_one_always_answers(): void
    {
        $category = Category::create(['name' => ['en' => 'Paged'], 'slug' => 'paged-cat', 'enabled' => true]);
        $product = Product::create(['sku' => 'PG-1', 'name' => ['en' => 'Paged one'], 'slug' => 'paged-one', 'price' => 10_00, 'stock' => 1, 'status' => 'active']);
        $product->categories()->attach($category->id);

        $this->get('http://localhost/catalog/paged-cat')->assertOk();
        $this->get('http://localhost/catalog/paged-cat?page=1')->assertOk();
        $this->get('http://localhost/catalog/paged-cat?page=99')->assertNotFound();
        $this->get('http://localhost/catalog?page=9999')->assertNotFound();
        $this->get('http://localhost/blog?page=999')->assertNotFound();

        Category::create(['name' => ['en' => 'Empty'], 'slug' => 'paged-empty', 'enabled' => true]);
        $this->get('http://localhost/catalog/paged-empty')->assertOk();
    }
}
