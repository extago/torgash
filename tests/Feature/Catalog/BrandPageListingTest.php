<?php

namespace Tests\Feature\Catalog;

use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;

/**
 * A brand's page is the catalogue's listing over the brand's products: a
 * filter panel with the categories they stand in, narrowing by `?in=`, and
 * no "Brand" box — every product there already is the brand.
 */
class BrandPageListingTest extends TestCase
{
    public function test_the_brand_page_filters_its_own_products(): void
    {
        $make = Feature::create(['name' => ['en' => 'Make'], 'code' => 'bp_make', 'purpose' => 'brand', 'input_type' => 'select', 'shown_in' => ['filters']]);
        $acme = FeatureValue::create(['feature_id' => $make->id, 'value' => ['en' => 'Acme'], 'slug' => 'bp-acme']);
        $other = FeatureValue::create(['feature_id' => $make->id, 'value' => ['en' => 'Other'], 'slug' => 'bp-other']);
        $anvils = Category::create(['name' => ['en' => 'Anvils'], 'slug' => 'bp-anvils', 'enabled' => true]);
        $rockets = Category::create(['name' => ['en' => 'Rockets'], 'slug' => 'bp-rockets', 'enabled' => true]);

        foreach ([['BP-1', 'Iron anvil', $anvils, $acme], ['BP-2', 'Red rocket', $rockets, $acme], ['BP-3', 'Foreign anvil', $anvils, $other]] as [$sku, $name, $category, $brand]) {
            $product = Product::create(['sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku), 'price' => 10_00, 'stock' => 1, 'status' => 'active']);
            $product->categories()->attach($category->id);
            ProductFeature::create(['product_id' => $product->id, 'feature_id' => $make->id, 'feature_value_id' => $brand->id]);
        }

        $page = $this->get('http://localhost/brand/bp-acme')->assertOk();
        $page->assertSee('Iron anvil')->assertSee('Red rocket')->assertDontSee('Foreign anvil');
        // The panel's Category block names where the brand stands.
        $page->assertSee('bp-anvils', false)->assertSee('bp-rockets', false);
        $brandBox = 'name="feature['.$make->id.'][]"';
        $this->assertStringNotContainsString($brandBox, $page->getContent(), 'no Brand box on a brand page');
        // ...where the same set elsewhere has one: the assertion above can fail.
        $this->assertStringContainsString($brandBox, $this->get('http://localhost/search?q=anvil')->getContent());

        $this->get('http://localhost/brand/bp-acme?in=bp-rockets')->assertOk()
            ->assertSee('Red rocket')->assertDontSee('Iron anvil');
    }
}
