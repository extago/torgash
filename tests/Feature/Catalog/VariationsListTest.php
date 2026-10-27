<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use Tests\Concerns\MakesVariationAxes;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * "All variants of this product" under the buy box: the group as a table,
 * every row bought as itself rather than through the choice window.
 */
class VariationsListTest extends TestCase
{
    use MakesVariationAxes;

    protected function product(string $sku, int $price): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku), 'price' => $price,
            'stock' => 5, 'status' => 'active', 'variation_group' => 'vl-phone',
        ]);
    }

    public function test_the_product_page_lists_every_variant_with_its_own_basket_button(): void
    {
        [, $colors] = $this->variationAxis('VL Colour', ['black', 'white']);
        $black = $this->product('VL-BLACK', 100_00);
        $white = $this->product('VL-WHITE', 120_00);
        $this->answer($black, $colors['black']);
        $this->answer($white, $colors['white']);

        $page = $this->get('http://localhost/product/vl-black')->assertOk()->getContent();

        $this->assertStringContainsString('All variants of this product (2)', $page);
        $this->assertStringContainsString('VL Colour', $page);
        $this->assertStringContainsString('data-sku="VL-WHITE"', $page);
        // Each row adds itself: an add form for the white one, not the
        // choice window a card of a variation group opens.
        $this->assertStringContainsString('action="'.route('cart.add', 'vl-white'), str_replace('hx-post=', 'action=', $page));
    }

    public function test_a_product_on_its_own_and_the_setting_switched_off_draw_nothing(): void
    {
        Product::create(['sku' => 'VL-ALONE', 'name' => ['en' => 'Alone'], 'slug' => 'vl-alone', 'price' => 100_00, 'stock' => 5, 'status' => 'active']);
        $this->get('http://localhost/product/vl-alone')->assertOk()->assertDontSee('All variants of this product');

        [, $colors] = $this->variationAxis('VL2 Colour', ['black', 'white']);
        $black = $this->product('VL2-BLACK', 100_00);
        $white = $this->product('VL2-WHITE', 120_00);
        $this->answer($black, $colors['black']);
        $this->answer($white, $colors['white']);
        app(SettingsManager::class)->set('torgash.catalog', 'variations_list', false);

        $this->get('http://localhost/product/vl2-black')->assertOk()->assertDontSee('All variants of this product');
    }
}
