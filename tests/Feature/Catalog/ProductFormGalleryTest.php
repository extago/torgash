<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * The gallery's inputs must be named after the gallery.
 *
 * They were not: a Blade `@include` inherits every variable in the caller's
 * scope, the product form loops `as $field => $label` over its dimensions, and
 * the gallery partial two hundred lines later defaulted `$field = $field ??
 * 'media'` — so it took the leftover `'height'` and named every input
 * `height[12][alt]`.
 *
 * Three symptoms, none of them near the cause: image captions were silently
 * never saved (the controller reads `media`), the form posted `height` as an
 * ARRAY so validation failed, and the re-rendered form then threw a 500 on
 * `old('height')`. This pins the input names down so a partial cannot inherit
 * them again.
 */
class ProductFormGalleryTest extends TestCase
{
    public function test_the_gallery_names_its_inputs_after_itself(): void
    {
        $product = Product::create([
            'sku' => 'GAL-1', 'name' => ['en' => 'Photographed'], 'slug' => 'photographed',
            'price' => 10_00, 'stock' => 1, 'status' => 'active',
        ]);

        $response = $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/catalog/products/'.$product->id.'/edit')
            ->assertOk();

        // The field the Alpine component builds every input name from, and the
        // one ProductsController reads back with `$request->input('media')`.
        $response->assertSee("field: 'media'", false);

        // The dimensions the loop is over: whichever of them leaks, the page is
        // wrong in the same way.
        foreach (['length', 'width', 'height'] as $dimension) {
            $response->assertDontSee("field: '{$dimension}'", false);
        }
    }

    /** ...and the dimension inputs are still the plain scalars they must be. */
    public function test_the_dimension_inputs_keep_their_own_names(): void
    {
        $product = Product::create([
            'sku' => 'GAL-2', 'name' => ['en' => 'Measured'], 'slug' => 'measured',
            'price' => 10_00, 'stock' => 1, 'status' => 'active', 'width' => 100,
        ]);

        $response = $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/catalog/products/'.$product->id.'/edit')
            ->assertOk();

        foreach (['length', 'width', 'height'] as $dimension) {
            $response->assertSee('name="'.$dimension.'"', false);
        }
    }
}
