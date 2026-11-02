<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;

/**
 * A characteristic may explain itself to a customer: an "i" beside its name
 * opens what the shop wrote. Written one language per save like any text;
 * no explanation, no icon.
 */
class FeatureExplanationTest extends TestCase
{
    public function test_an_explained_characteristic_carries_an_icon_and_a_window(): void
    {
        $product = Product::create(['sku' => 'FX-1', 'name' => ['en' => 'FX Phone'], 'slug' => 'fx-phone', 'price' => 100_00, 'stock' => 5, 'status' => 'active']);
        $explained = Feature::create(['code' => 'fx_nits', 'name' => ['en' => 'FX Brightness'], 'purpose' => 'filter', 'input_type' => 'text', 'shown_in' => ['specs'],
            'description' => ['en' => '<p>How bright the screen gets in <b>nits</b>.</p><script>alert(1)</script>']]);
        $plain = Feature::create(['code' => 'fx_colour', 'name' => ['en' => 'FX Colour'], 'purpose' => 'filter', 'input_type' => 'text', 'shown_in' => ['specs']]);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $explained->id, 'value_text' => '2000']);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $plain->id, 'value_text' => 'Black']);

        $html = $this->get('http://localhost/product/fx-phone')->assertOk()->getContent();

        $this->assertStringContainsString('How bright the screen gets in <b>nits</b>.', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'staff text is cleaned');
        $this->assertSame(1, substr_count($html, 'data-open-dialog="modal-feature-info-'.$explained->id.'-'));
        $this->assertStringNotContainsString('modal-feature-info-'.$plain->id.'-', $html, 'nothing written, no icon');
    }

    public function test_each_place_is_switched_on_its_own(): void
    {
        $category = Category::create(['name' => ['en' => 'FX Things'], 'slug' => 'fx-things', 'enabled' => true]);
        $feature = Feature::create(['code' => 'fx_size', 'name' => ['en' => 'FX Size'], 'purpose' => 'filter', 'input_type' => 'select', 'shown_in' => ['filters', 'specs'],
            'description' => ['en' => 'Measured across the diagonal.'], 'explain_on_product' => false, 'explain_in_filter' => true]);
        $value = FeatureValue::create(['feature_id' => $feature->id, 'value' => ['en' => '42 mm']]);
        $product = Product::create(['sku' => 'FX-3', 'name' => ['en' => 'FX Watch'], 'slug' => 'fx-watch', 'price' => 100_00, 'stock' => 5, 'status' => 'active']);
        $product->categories()->attach($category->id);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $feature->id, 'feature_value_id' => $value->id]);

        $listing = $this->get('http://localhost/catalog/fx-things')->assertOk()->getContent();
        $this->assertSame(1, substr_count($listing, 'data-open-dialog="modal-filter-info-'.$feature->id.'-'), 'the "i" in the filter heading');
        $this->assertSame(1, substr_count($listing, 'id="modal-filter-info-'.$feature->id.'-'), 'its window, once');
        $this->assertStringContainsString('Measured across the diagonal.', $listing);

        $this->assertStringNotContainsString('Measured across the diagonal.', $this->get('http://localhost/product/fx-watch')->assertOk()->getContent(), 'switched off on the product page');

        $feature->update(['explain_on_product' => true, 'explain_in_filter' => false]);

        $this->assertStringNotContainsString('Measured across the diagonal.', $this->get('http://localhost/catalog/fx-things')->getContent());
        $this->assertStringContainsString('Measured across the diagonal.', $this->get('http://localhost/product/fx-watch')->getContent());
    }

    public function test_the_explanation_is_written_one_language_at_a_time(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $feature = Feature::create(['code' => 'fx_ip', 'name' => ['en' => 'FX Protection'], 'purpose' => 'filter', 'input_type' => 'text',
            'description' => ['en' => 'Old', 'ru' => 'Защита от воды']]);

        $this->actingAs($admin)->put('http://localhost/admin/catalog/features/'.$feature->id, [
            'name' => 'FX Protection', 'purpose' => 'filter', 'input_type' => 'text', 'description' => '<p>Water and dust</p>',
            'explain_on_product' => '1', 'explain_in_filter' => '0',
        ])->assertRedirect();

        $this->assertTrue($feature->fresh()->explain_on_product);
        $this->assertFalse($feature->fresh()->explain_in_filter);

        $this->assertSame(['en' => '<p>Water and dust</p>', 'ru' => 'Защита от воды'], $feature->fresh()->description);

        $this->actingAs($admin)->put('http://localhost/admin/catalog/features/'.$feature->id, [
            'name' => 'FX Protection', 'purpose' => 'filter', 'input_type' => 'text', 'description' => '<p><br></p>',
        ])->assertRedirect();

        $this->assertSame(['ru' => 'Защита от воды'], $feature->fresh()->description, 'an empty editor removes that language only');
    }

    public function test_the_small_buy_card_offers_the_way_back_up_when_asked(): void
    {
        Product::create(['sku' => 'FX-2', 'name' => ['en' => 'FX Lamp'], 'slug' => 'fx-lamp', 'price' => 100_00, 'stock' => 5, 'status' => 'active']);

        $this->get('http://localhost/product/fx-lamp')->assertOk()->assertDontSee('Back to the top');

        app(SettingsManager::class)->set('torgash.catalog', 'sticky_buy_card_top', true);

        $this->get('http://localhost/product/fx-lamp')->assertOk()->assertSee('Back to the top');
    }
}
