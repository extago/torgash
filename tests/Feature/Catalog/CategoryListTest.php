<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;

/** The categories list: searched, narrowed by status, acted on in bulk; the form's description. */
class CategoryListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    protected function category(string $name, bool $enabled = true, ?string $ru = null, ?int $parent = null): Category
    {
        $category = Category::create([
            'name' => array_filter(['en' => $name, 'ru' => $ru]),
            'slug' => \Illuminate\Support\Str::slug($name),
            'parent_id' => $parent,
            'sort' => 0,
            'enabled' => $enabled,
        ]);
        app(CategoryTree::class)->flush();

        return $category;
    }

    public function test_search_reads_every_language_and_the_address_and_shows_the_path(): void
    {
        $parent = $this->category('CL Outdoor');
        $this->category('CL Tents', ru: 'КЛ Палатки', parent: $parent->id);
        $this->category('CL Kettles');

        $this->get('http://localhost/admin/catalog/categories?q=Палатки')->assertOk()
            ->assertSee('CL Tents')->assertSee('CL Outdoor ·', false)->assertDontSee('CL Kettles');

        $this->get('http://localhost/admin/catalog/categories?q=cl-kettles')->assertOk()
            ->assertSee('CL Kettles')->assertDontSee('CL Tents');
    }

    public function test_the_list_narrows_by_status(): void
    {
        $this->category('CL Shown one');
        $this->category('CL Off one', enabled: false);
        $this->category('CL Hidden one')->update(['status' => Category::STATUS_HIDDEN]);

        $this->get('http://localhost/admin/catalog/categories?status=disabled&q=CL+')->assertOk()
            ->assertSee('CL Off one')->assertDontSee('CL Shown one')->assertDontSee('CL Hidden one');
        $this->get('http://localhost/admin/catalog/categories?status=hidden&q=CL+')->assertOk()
            ->assertSee('CL Hidden one')->assertDontSee('CL Shown one')->assertDontSee('CL Off one');
    }

    public function test_bulk_switches_and_trashes(): void
    {
        $a = $this->category('CL Bulk A');
        $b = $this->category('CL Bulk B');
        $c = $this->category('CL Bulk C');

        $this->post('http://localhost/admin/catalog/categories/bulk', ['action' => 'hidden', 'ids' => [$a->id]])->assertRedirect();
        $this->post('http://localhost/admin/catalog/categories/bulk', ['action' => 'disable', 'ids' => [$b->id]])->assertRedirect();
        $this->assertSame(['hidden', 'disabled', 'active'], [$a->fresh()->status, $b->fresh()->status, $c->fresh()->status]);

        $this->post('http://localhost/admin/catalog/categories/bulk', ['action' => 'trash', 'ids' => [$c->id]])->assertRedirect();
        $this->assertNull(Category::find($c->id));
        $this->assertNotNull(Category::withTrashed()->find($c->id));

        $this->post('http://localhost/admin/catalog/categories/bulk', ['action' => 'purge', 'ids' => [$a->id]])->assertSessionHasErrors('action');
    }

    public function test_the_description_is_saved_and_printed_under_the_products(): void
    {
        $category = $this->category('CL Described');

        $this->get('http://localhost/admin/catalog/categories/'.$category->id.'/edit')->assertOk();

        $this->put('http://localhost/admin/catalog/categories/'.$category->id, [
            'name' => 'CL Described',
            'slug' => 'cl-described',
            'description' => '<p>All about CL tents.</p>',
            'enabled' => '1',
        ])->assertRedirect();

        $this->assertSame('<p>All about CL tents.</p>', $category->fresh()->description['en'] ?? null);

        $this->get('http://localhost/catalog/cl-described')->assertOk()->assertSee('<p>All about CL tents.</p>', false);

        // A real second page — one product a page — and the text is not on it.
        app(\App\Core\Settings\SettingsManager::class)->set('torgash.catalog', 'per_page_choices', '1 24');
        foreach (['CL-T1', 'CL-T2'] as $sku) {
            Product::create(['sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku), 'price' => 10_00, 'stock' => 1, 'status' => 'active'])
                ->categories()->attach($category->id);
        }
        $this->get('http://localhost/catalog/cl-described?page=2&per_page=1')->assertOk()->assertDontSee('<p>All about CL tents.</p>', false);
    }

    /** Its placeholders are the category's, under the goods and in the description made of it. */
    public function test_the_description_takes_placeholders(): void
    {
        $category = $this->category('CL Counted');
        $category->update(['description' => ['en' => '<p>{category}: {total_products} on the shelf.</p>']]);
        Product::create(['sku' => 'CL-C1', 'name' => ['en' => 'CL-C1'], 'slug' => 'cl-c1', 'price' => 10_00, 'stock' => 1, 'status' => 'active'])
            ->categories()->attach($category->id);

        $this->get('http://localhost/catalog/'.$category->slug)->assertOk()
            ->assertSee('<p>CL Counted: 1 on the shelf.</p>', false)
            ->assertSee('<meta name="description" content="CL Counted: 1 on the shelf.">', false);
    }

    public function test_a_row_switches_its_status_in_place(): void
    {
        $category = $this->category('CL Switch');

        $this->get('http://localhost/admin/catalog/categories?q=CL+Switch')->assertOk()
            ->assertSee(route('admin.catalog.categories.status', $category), false);

        $this->patch('http://localhost/admin/catalog/categories/'.$category->id.'/status', ['status' => 'hidden'])
            ->assertOk()
            ->assertSee('id="category-status-'.$category->id.'"', false)
            ->assertSee('hx-swap-oob="true"', false);

        $this->assertSame(Category::STATUS_HIDDEN, $category->fresh()->status);

        $this->patch('http://localhost/admin/catalog/categories/'.$category->id.'/status', ['status' => 'gone'])
            ->assertSessionHasErrors('status');
    }

    public function test_saving_stays_on_the_form_unless_asked_to_close(): void
    {
        $category = $this->category('CL Stay');
        $url = 'http://localhost/admin/catalog/categories/'.$category->id;

        $this->put($url, ['name' => 'CL Stay', 'slug' => 'cl-stay'])
            ->assertRedirect(route('admin.catalog.categories.edit', $category));

        $this->put($url, ['name' => 'CL Stay', 'slug' => 'cl-stay', 'after_save' => 'close'])
            ->assertRedirect(route('admin.catalog.categories.index'));

        $this->post('http://localhost/admin/catalog/categories', ['name' => 'CL Created', 'slug' => 'cl-created'])
            ->assertRedirect(route('admin.catalog.categories.edit', Category::where('slug', 'cl-created')->firstOrFail()));
    }

    public function test_the_gear_menu_starts_a_subcategory_and_a_product_in_this_category(): void
    {
        $category = $this->category('CL Gear');

        $this->get('http://localhost/admin/catalog/categories/'.$category->id.'/edit')->assertOk()
            ->assertSee(route('admin.catalog.categories.create', ['parent' => $category->id]), false)
            ->assertSee(route('admin.catalog.products.create', ['category' => $category->id]), false)
            ->assertSee(route('admin.catalog.products.index', ['category' => $category->id]), false)
            ->assertSee('form="category-delete"', false);

        $this->get('http://localhost/admin/catalog/categories/create?parent='.$category->id)->assertOk()
            ->assertSee('<option value="'.$category->id.'" selected', false);

        // The product form's category picker opens with this category chosen.
        $chosen = 'selected: '.\Illuminate\Support\Js::from([$category->id]);
        $this->get('http://localhost/admin/catalog/products/create')->assertOk()->assertDontSee($chosen, false);
        $this->get('http://localhost/admin/catalog/products/create?category='.$category->id)->assertOk()->assertSee($chosen, false);
    }
}
