<?php

namespace Tests\Feature\Catalog;

use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;

/**
 * A disabled category as a TECHNICAL grouping.
 *
 * A shop that wants one arbitrary set of products — for a promotion's
 * conditions, for an automation rule, for both at once — does not need a new
 * kind of entity: it puts them in a category and switches that category off.
 * The category then has no page, no menu entry and no place in the sitemap,
 * while the products in it stay exactly as visible as they were.
 *
 * The one place it used to leak was the product page's breadcrumbs, which
 * printed EVERY category the product belongs to — including a dead link to a
 * page that 404s.
 */
class TechnicalCategoryTest extends TestCase
{
    public function test_a_disabled_category_stays_out_of_the_breadcrumbs_but_keeps_its_products_visible(): void
    {
        $shown = Category::create(['name' => ['en' => 'Laptops shown'], 'slug' => 'laptops-shown', 'enabled' => true]);
        $technical = Category::create(['name' => ['en' => 'Sale group Q4'], 'slug' => 'sale-group-q4', 'enabled' => false]);

        $product = Product::create([
            'sku' => 'TECHCAT-1', 'name' => ['en' => 'Grouped laptop'], 'slug' => 'grouped-laptop',
            'price' => 1000_00, 'stock' => 3, 'status' => 'active',
        ]);
        $product->categories()->sync([$shown->id, $technical->id]);

        $page = $this->get('http://localhost/product/grouped-laptop')->assertOk();

        $page->assertSee('Laptops shown');
        $page->assertDontSee('Sale group Q4', escape: false);
        $page->assertDontSee('/category/sale-group-q4', escape: false);
    }

    /** The category itself is unreachable — which is what "technical" means. */
    public function test_a_disabled_category_has_no_page_of_its_own(): void
    {
        Category::create(['name' => ['en' => 'Sale group Q4'], 'slug' => 'q4-hidden', 'enabled' => false]);

        $this->get('http://localhost/category/q4-hidden')->assertNotFound();
    }

    /** But a rule can still point at it: the membership is ordinary. */
    public function test_products_in_a_technical_category_are_still_matched_by_it(): void
    {
        $technical = Category::create(['name' => ['en' => 'Clearance'], 'slug' => 'clearance-hidden', 'enabled' => false]);

        $product = Product::create([
            'sku' => 'TECHCAT-2', 'name' => ['en' => 'Clearance item'], 'slug' => 'clearance-item',
            'price' => 500_00, 'stock' => 1, 'status' => 'active',
        ]);
        $product->categories()->sync([$technical->id]);

        $this->assertTrue(
            \Torgash\Promotions\Conditions::matchesProduct(
                [['kind' => 'category', 'operator' => 'cat_in', 'ids' => [$technical->id]]],
                false,
                $product->fresh()->load('categories'),
            ),
            'a promotion condition sees the hidden category like any other',
        );
    }
}
