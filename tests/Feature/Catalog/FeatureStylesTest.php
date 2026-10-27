<?php

namespace Tests\Feature\Catalog;

use Tests\Concerns\MakesVariationAxes;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * How a characteristic's values are DRAWN — on the product page's variation
 * picker (`product_style`) and in the filter panel (`filter_style`) — while
 * the control underneath stays the same link or the same checkbox.
 */
class FeatureStylesTest extends TestCase
{
    use MakesVariationAxes;

    /** Swatches need a colour characteristic; an unknown word is the default. */
    public function test_a_style_that_cannot_be_drawn_falls_back(): void
    {
        [$feature] = $this->variationAxis('FS Size', ['S', 'M']);

        $feature->update(['product_style' => 'swatches', 'filter_style' => 'swatches']);
        $this->assertSame('labels', $feature->productStyle());
        $this->assertSame('list', $feature->filterStyle());

        $feature->update(['is_color' => true]);
        $this->assertSame('swatches', $feature->fresh()->productStyle());
        $this->assertSame('swatches', $feature->fresh()->filterStyle());

        $feature->update(['product_style' => 'nonsense']);
        $this->assertSame('labels', $feature->fresh()->productStyle());
    }

    /**
     * Pictures of the variations: each member's tile is a link to it, and a
     * tile other than the page's own previews its photo in the gallery while
     * pointed at — unless the shop switched that off.
     */
    public function test_the_picker_draws_pictures_and_previews_on_hover(): void
    {
        [$colour, $colours] = $this->variationAxis('FS Colour', ['Black', 'White']);
        $colour->update(['product_style' => 'images']);

        $black = $this->product('FS-BLACK');
        $white = $this->product('FS-WHITE');
        $this->answer($black, $colours['Black']);
        $this->answer($white, $colours['White']);

        $html = $this->get('http://localhost/product/'.$black->slug)->assertOk()->getContent();

        $this->assertStringContainsString('FS Colour:', $html);
        $this->assertStringContainsString('href="http://localhost/product/fs-white"', $html);
        $this->assertStringContainsString('w-[5rem]', $html, 'tiles, not chips');

        $colour->update(['product_style' => 'dropdown']);
        $html = $this->get('http://localhost/product/'.$black->slug)->getContent();
        $this->assertMatchesRegularExpression('/<option value="http:\/\/localhost\/product\/fs-white"/', $html);
    }

    /** In the filter, chips are the same checkbox — visually hidden, its label drawn from its state. */
    public function test_filter_chips_keep_the_checkbox(): void
    {
        [$size, $sizes] = $this->variationAxis('FS Fit', ['Slim', 'Wide'], 'filter');
        $size->update(['filter_style' => 'chips']);

        $this->answer($this->product('FS-SLIM', ['variation_group' => null]), $sizes['Slim']);
        $this->answer($this->product('FS-WIDE', ['variation_group' => null]), $sizes['Wide']);

        $html = $this->get('http://localhost/catalog')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<input type="checkbox" name="feature\['.$size->id.'\]\[\]" value="'.$sizes['Slim']->id.'" class="peer sr-only"/',
            $html,
        );
        $this->assertStringContainsString('peer-checked:border-primary', $html);
    }

    protected function product(string $sku, array $extra = []): Product
    {
        return Product::create($extra + [
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku), 'price' => 100_00,
            'stock' => 5, 'status' => 'active', 'variation_group' => 'fs-group',
        ]);
    }
}
