<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\Labels;

/**
 * How a label looks, per place: a size for the card and one for the page, the
 * shop's own colours for each theme, a picture's height and shape, and a hint.
 */
class LabelAppearanceTest extends TestCase
{
    protected function product(Label $label): Product
    {
        $product = Product::create([
            'sku' => 'LA-1', 'name' => ['en' => 'Boots'], 'slug' => 'la-boots',
            'price' => 100_00, 'stock' => 3, 'status' => 'active',
        ]);
        $product->labels()->sync([$label->id]);
        app(Labels::class)->flush();

        return $product;
    }

    public function test_the_form_stores_the_look_and_the_hint(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());
        $label = Label::create(['name' => ['en' => 'FR'], 'name_for_admin' => 'LA French', 'color' => 'primary', 'enabled' => true]);

        $this->put('http://localhost/admin/catalog/labels/'.$label->id, [
            'name' => 'FR', 'name_for_admin' => 'LA French', 'style' => 'text', 'color' => 'custom',
            'colors' => ['light' => ['bg' => '#dce8a0', 'text' => '#1f2937'], 'dark' => ['bg' => '#3f4a14', 'text' => '#f9fafb']],
            'colors_dark' => '1',
            'size_list' => 'xs', 'size_detail' => 'lg',
            'image_height_list' => 24, 'image_height_detail' => 48, 'image_shape' => 'circle',
            'tooltip' => 'A French brand',
            'position_list' => 'top-left', 'position_detail' => 'top-left', 'enabled' => '1',
        ])->assertRedirect();

        $label->refresh();
        $this->assertSame(['xs', 'lg', 'circle'], [$label->size_list, $label->size_detail, $label->image_shape]);
        $this->assertSame('#3f4a14', $label->custom_colors['dark']['bg']);
        $this->assertSame('A French brand', $label->tooltipText('en'));

        // A colour that is not a hex never reaches a style attribute.
        $this->put('http://localhost/admin/catalog/labels/'.$label->id, [
            'name' => 'FR', 'name_for_admin' => 'LA French', 'style' => 'text', 'color' => 'custom',
            'colors' => ['light' => ['bg' => 'red;background:url(x)', 'text' => '#ffffff']],
            'position_list' => 'top-left', 'position_detail' => 'top-left',
        ])->assertSessionHasErrors('colors.light.bg');
    }

    public function test_the_storefront_draws_each_place_at_its_own_size_with_its_hint(): void
    {
        $label = Label::create([
            'name' => ['en' => 'FR'], 'name_for_admin' => 'LA French', 'enabled' => true,
            'color' => 'custom', 'custom_colors' => ['light' => ['bg' => '#dce8a0', 'text' => '#1f2937']],
            'size_list' => 'xs', 'size_detail' => 'lg', 'tooltip' => ['en' => 'A French brand'],
        ]);
        $this->product($label);

        $page = $this->get('http://localhost/product/la-boots')->assertOk();
        $page->assertSee('badge torgash-label badge-lg torgash-label-custom', false)
            ->assertSee('--lbl-bg: #dce8a0', false)
            ->assertSee('A French brand');

        $this->get('http://localhost/catalog')->assertOk()
            ->assertSee('badge torgash-label badge-xs torgash-label-custom', false)
            ->assertSee('torgash-label-tip-panel', false);
    }

    public function test_a_picture_is_cut_to_its_shape(): void
    {
        $label = new Label(['image_height_list' => 32, 'image_shape' => 'circle']);

        $box = $label->imageBox('list');
        $this->assertStringContainsString('width: 32px', $box['style']);
        $this->assertStringContainsString('border-radius: 50%', $box['style']);

        $label->image_shape = 'as_is';
        $this->assertStringContainsString('width: auto', $label->imageBox('list')['style']);
    }
}
