<?php

namespace Tests\Feature\Catalog;

use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Seo\ProductFacts;
use Torgash\Catalog\Seo\ProductSchema;

/**
 * A product nobody described still gets a sentence, and its structured data
 * claims nothing it does not have (audit 2026-10-15: "Apple iPhone 17. 78990
 * USD" in search results, `"description": ""` and `"image": []` in JSON-LD).
 */
class ProductSummaryTest extends TestCase
{
    public function test_an_undescribed_product_is_summed_up_as_a_sentence_with_the_price_written_properly(): void
    {
        $product = Product::create(['sku' => 'SUM-1', 'name' => ['en' => 'Plain kettle'], 'slug' => 'plain-kettle', 'price' => 1234_50, 'stock' => 1, 'status' => 'active']);

        $summary = (new ProductFacts($product->fresh()))->summary();

        $this->assertStringStartsWith('Plain kettle', $summary);
        $this->assertStringContainsString('Price ', $summary);
        $this->assertStringNotContainsString('123450', $summary, 'the price is written, not dumped in minor units');
    }

    public function test_structured_data_leaves_out_what_the_product_does_not_have(): void
    {
        $product = Product::create(['sku' => 'SUM-2', 'name' => ['en' => 'Bare kettle'], 'slug' => 'bare-kettle', 'price' => 10_00, 'stock' => 1, 'status' => 'active']);

        $schema = ProductSchema::for($product->fresh());

        $this->assertArrayNotHasKey('image', $schema);
        $this->assertTrue(! array_key_exists('description', $schema) || $schema['description'] !== '');
        $this->assertSame('Bare kettle', $schema['name']);
    }
}
