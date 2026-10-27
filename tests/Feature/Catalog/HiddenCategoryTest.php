<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;

/**
 * A category's three states, as a product has them: active is listed and
 * opens, hidden opens by its address and is listed NOWHERE, disabled has no
 * page. Hidden is the "Black Friday" page a banner leads to and the navigation
 * does not.
 */
class HiddenCategoryTest extends TestCase
{
    protected Category $parent;

    protected Category $hidden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parent = Category::create(['name' => ['en' => 'HC Outdoor'], 'slug' => 'hc-outdoor']);
        $this->hidden = Category::create([
            'name' => ['en' => 'HC Black Friday'], 'slug' => 'hc-black-friday',
            'parent_id' => $this->parent->id, 'status' => Category::STATUS_HIDDEN,
        ]);

        $product = Product::create([
            'sku' => 'HC-1', 'name' => ['en' => 'HC tent'], 'slug' => 'hc-tent',
            'price' => 100_00, 'stock' => 3, 'status' => 'active',
        ]);
        $product->categories()->sync([$this->hidden->id]);
    }

    public function test_a_new_category_is_active_before_it_is_read_back(): void
    {
        $category = Category::create(['name' => ['en' => 'HC Fresh'], 'slug' => 'hc-fresh']);

        $this->assertSame(Category::STATUS_ACTIVE, $category->status);
        $this->assertTrue($category->isListed());
    }

    public function test_a_hidden_category_opens_by_its_address(): void
    {
        $this->get('http://localhost/catalog/hc-black-friday')->assertOk()
            ->assertSee('HC Black Friday')->assertSee('HC tent');
    }

    public function test_a_hidden_category_is_in_no_list(): void
    {
        // Not among its parent's subcategories...
        $this->get('http://localhost/catalog/hc-outdoor')->assertOk()
            ->assertDontSee('/catalog/hc-black-friday', false);

        // ...not in the sitemap...
        $this->get('http://localhost/sitemap.xml')->assertOk()
            ->assertDontSee('hc-black-friday', false);

        // ...and not in the product's trail, which is navigation too.
        $this->get('http://localhost/product/hc-tent')->assertOk()
            ->assertDontSee('/catalog/hc-black-friday', false);

        // The shop API's tree is the storefront's menu.
        $this->getJson('http://localhost/api/v2/shop/categories?per_page=200')->assertOk()
            ->assertJsonMissing(['slug' => 'hc-black-friday']);
    }

    public function test_a_disabled_category_still_has_no_page(): void
    {
        $this->hidden->update(['status' => Category::STATUS_DISABLED]);

        $this->get('http://localhost/catalog/hc-black-friday')->assertNotFound();
    }

    public function test_the_old_boolean_still_writes_the_old_meaning(): void
    {
        $off = Category::create(['name' => ['en' => 'HC Off'], 'slug' => 'hc-off', 'enabled' => false]);

        $this->assertSame(Category::STATUS_DISABLED, $off->fresh()->status);
        $this->assertFalse($this->hidden->fresh()->enabled, 'read as "listed": the stricter answer');
    }

    public function test_the_form_saves_the_status(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->get('http://localhost/admin/catalog/categories/'.$this->parent->id.'/edit')->assertOk()
            ->assertSee('name="status"', false);

        $this->put('http://localhost/admin/catalog/categories/'.$this->parent->id, [
            'name' => 'HC Outdoor', 'slug' => 'hc-outdoor', 'status' => 'hidden',
        ])->assertRedirect();

        $this->assertSame(Category::STATUS_HIDDEN, $this->parent->fresh()->status);

        // Saying nothing about the status changes nothing.
        $this->put('http://localhost/admin/catalog/categories/'.$this->parent->id, [
            'name' => 'HC Outdoor', 'slug' => 'hc-outdoor',
        ])->assertRedirect();

        $this->assertSame(Category::STATUS_HIDDEN, $this->parent->fresh()->status);
    }
}
