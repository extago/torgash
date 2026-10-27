<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductTab;
use Torgash\Catalog\Support\ProductSections;

/**
 * A module's section on the product page (the reviews) answers to a row
 * exactly as the page's own two do: renamed, moved, headed, switched off —
 * and its count survives the new name.
 */
class ModuleSectionTabsTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Sections are asked for here outside a request; their views expect
        // the shared error bag a request would have put there.
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);

        $this->product = Product::create([
            'sku' => 'MST-1', 'name' => ['en' => 'Lamp'], 'slug' => 'mst-lamp',
            'price' => 100_00, 'stock' => 5, 'status' => 'active',
        ]);
    }

    /** @return array<string, mixed>|null */
    protected function reviews(): ?array
    {
        return collect(app(ProductSections::class)->for($this->product->fresh()))->firstWhere('key', 'reviews');
    }

    public function test_the_reviews_are_a_named_section_with_their_count_apart(): void
    {
        $this->assertArrayHasKey('reviews', ProductSections::known());

        $section = $this->reviews();
        $this->assertSame('Reviews', $section['label']);
        $this->assertSame(30, $section['sort']);
        $this->assertArrayHasKey('count', $section);
    }

    public function test_a_row_renames_moves_and_heads_the_reviews(): void
    {
        ProductTab::create([
            'section' => 'reviews', 'title' => ['en' => 'Customer reviews'],
            'heading' => ['en' => 'What people say about {product}'], 'sort' => 5, 'enabled' => true,
        ]);

        $section = $this->reviews();
        $this->assertSame('Customer reviews', $section['label']);
        $this->assertSame('What people say about Lamp', $section['heading']);
        $this->assertSame(5, $section['sort']);
        $this->assertArrayHasKey('count', $section, 'the count is kept apart from the new name');

        // ...and it now stands first.
        $this->assertSame('reviews', app(ProductSections::class)->for($this->product->fresh())[0]['key']);
    }

    public function test_a_switched_off_row_hides_the_reviews(): void
    {
        ProductTab::create(['section' => 'reviews', 'title' => [], 'sort' => 30, 'enabled' => false]);

        $this->assertNull($this->reviews());
    }

    public function test_the_admin_opens_and_saves_a_row_for_the_reviews(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->get('http://localhost/admin/catalog/product-tabs/create?section=reviews')->assertOk()
            ->assertSee('value="reviews"', false)
            ->assertSee('value="30"', false);

        $this->post('http://localhost/admin/catalog/product-tabs', [
            'section' => 'reviews', 'title' => 'Customer reviews', 'sort' => 12, 'enabled' => '1',
        ])->assertRedirect();

        $this->assertSame(12, (int) ProductTab::where('section', 'reviews')->value('sort'));

        // Kept under its usual name: no title typed is still a row that saves.
        $this->post('http://localhost/admin/catalog/product-tabs', ['section' => 'specs', 'sort' => 22, 'enabled' => '1'])
            ->assertRedirect()->assertSessionHasNoErrors();

        // A section nobody declares is not a name a row can be saved under.
        $this->post('http://localhost/admin/catalog/product-tabs', ['section' => 'nobody', 'sort' => 1])
            ->assertSessionHasErrors('section');
    }
}
