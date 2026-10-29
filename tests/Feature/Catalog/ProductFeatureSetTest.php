<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;

/**
 * The product form shows the product's own set of characteristics — what
 * its categories declare plus what it already has — reaches any other by
 * name, and saves only what it sent (PLAN §63.9).
 */
class ProductFeatureSetTest extends TestCase
{
    protected User $admin;

    protected Category $phones;

    protected Category $laptops;

    protected Feature $forPhones;

    protected Feature $forLaptops;

    protected Feature $forAll;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();

        $this->phones = Category::create(['name' => ['en' => 'FS Phones'], 'slug' => 'fs-phones']);
        $this->laptops = Category::create(['name' => ['en' => 'FS Laptops'], 'slug' => 'fs-laptops']);

        $this->forPhones = $this->feature('fs_screen', 'FS Screen size');
        $this->forPhones->categories()->sync([$this->phones->id]);
        $this->forLaptops = $this->feature('fs_keyboard', 'FS Keyboard layout');
        $this->forLaptops->categories()->sync([$this->laptops->id]);
        $this->forAll = $this->feature('fs_warranty', 'FS Warranty');

        $this->product = Product::create([
            'sku' => 'FS-1', 'name' => ['en' => 'FS Phone'], 'slug' => 'fs-phone', 'price' => 10_00, 'stock' => 1, 'status' => 'active',
        ]);
        $this->product->categories()->sync([$this->phones->id]);
    }

    protected function feature(string $code, string $name): Feature
    {
        return Feature::create(['code' => $code, 'name' => ['en' => $name], 'purpose' => 'filter', 'input_type' => 'text', 'shown_in' => ['specs']]);
    }

    public function test_the_form_shows_the_products_own_set_and_what_it_already_has(): void
    {
        $html = $this->actingAs($this->admin)->get('http://localhost/admin/catalog/products/'.$this->product->id.'/edit')->assertOk()->getContent();

        $this->assertStringContainsString('FS Screen size', $html, 'bound to the product\'s category');
        $this->assertStringContainsString('FS Warranty', $html, 'bound to no category: every product\'s');
        $this->assertStringNotContainsString('FS Keyboard layout', $html, 'another category\'s');

        // Given a value however, a characteristic stays on the form.
        $this->product->featureAssignments()->create(['feature_id' => $this->forLaptops->id, 'value_text' => 'QWERTY']);
        $this->actingAs($this->admin)->get('http://localhost/admin/catalog/products/'.$this->product->id.'/edit')
            ->assertOk()->assertSee('FS Keyboard layout')->assertSee('QWERTY');
    }

    public function test_any_other_is_reached_by_name_and_drawn_as_a_field(): void
    {
        $this->actingAs($this->admin)
            ->get('http://localhost/admin/catalog/products/features/search?feature_q=keyboard&on_form[]='.$this->forPhones->id)
            ->assertOk()->assertSee('FS Keyboard layout')->assertDontSee('FS Screen size');

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/catalog/products/features/search?feature_q=screen&on_form[]='.$this->forPhones->id)
            ->assertOk()->assertDontSee('FS Screen size');

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/catalog/products/features/field?feature='.$this->forLaptops->id.'&product='.$this->product->id)
            ->assertOk()->assertSee('name="global_features['.$this->forLaptops->id.']"', false);
    }

    public function test_the_save_touches_only_what_the_form_sent(): void
    {
        $this->product->featureAssignments()->create(['feature_id' => $this->forLaptops->id, 'value_text' => 'QWERTY']);
        $this->product->featureAssignments()->create(['feature_id' => $this->forAll->id, 'value_text' => '2 years']);

        $this->actingAs($this->admin)->put('http://localhost/admin/catalog/products/'.$this->product->id, [
            'name' => 'FS Phone', 'sku' => 'FS-1', 'slug' => 'fs-phone', 'price' => '10', 'stock' => '1', 'status' => 'active',
            'categories' => [$this->phones->id],
            // Sent: the phone's own, filled; the warranty, emptied. Not sent: the keyboard.
            'global_features' => [$this->forPhones->id => '6.1"', $this->forAll->id => ''],
        ])->assertSessionDoesntHaveErrors()->assertRedirect();

        $values = $this->product->featureAssignments()->pluck('value_text', 'feature_id');
        $this->assertSame('6.1"', $values[$this->forPhones->id]);
        $this->assertArrayNotHasKey($this->forAll->id, $values->all(), 'an emptied field removes the value');
        $this->assertSame('QWERTY', $values[$this->forLaptops->id], 'a characteristic the form never drew is not touched');
    }
}
