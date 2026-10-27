<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;

/**
 * The brand's logo at the top of the product's buy panel, leading to the
 * brand's page — only when the brand has a logo, and only while the setting
 * is on.
 */
class BrandLogoTest extends TestCase
{
    public function test_the_brand_logo_leads_to_the_brand_page(): void
    {
        Storage::fake('public');

        $make = Feature::create(['name' => ['en' => 'Make'], 'code' => 'bl_make', 'purpose' => 'brand', 'input_type' => 'select']);
        $brand = FeatureValue::create(['feature_id' => $make->id, 'value' => ['en' => 'Acme'], 'slug' => 'bl-acme']);
        $product = Product::create([
            'sku' => 'BL-1', 'name' => ['en' => 'Anvil'], 'slug' => 'bl-anvil', 'price' => 10_00, 'stock' => 1, 'status' => 'active',
        ]);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $make->id, 'feature_value_id' => $brand->id]);

        $this->assertSame($brand->id, $product->fresh()->brandValue()?->id);

        $plain = $this->get('http://localhost/product/bl-anvil')->assertOk()->getContent();
        $this->assertStringNotContainsString('torgash-brand-logo', $plain, 'no logo, nothing drawn');

        $file = tempnam(sys_get_temp_dir(), 'logo').'.png';
        imagepng(imagecreatetruecolor(4, 4), $file);
        $brand->addMedia($file)->toMediaCollection('logo');

        $page = $this->get('http://localhost/product/bl-anvil')->assertOk()->getContent();
        $this->assertStringContainsString('torgash-brand-logo', $page);
        $this->assertStringContainsString('href="'.route('catalog.brands.show', 'bl-acme').'"', $page);
        $this->assertStringContainsString('alt="Acme"', $page);

        app(SettingsManager::class)->set('torgash.catalog', 'brand_logo_on_product', false);
        $off = $this->get('http://localhost/product/bl-anvil')->assertOk()->getContent();
        $this->assertStringNotContainsString('torgash-brand-logo', $off);
    }
}
