<?php

namespace Tests\Feature\Catalog;

use App\Core\Seo\RedirectManager;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Filters\Generator;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\FilterRule;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;

/**
 * Filter pages and their rules in the admin's common shape — search, tabs,
 * the status switched in place, several at once, a form that stays open on
 * Save — plus the number the list warns about: a page's product count is
 * taken when it is saved by hand and on a full run, not only when a rule
 * happens to reach it.
 */
class FilterPagesListTest extends TestCase
{
    protected User $admin;

    protected Category $category;

    protected Feature $feature;

    protected FeatureValue $apple;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
        session(['admin_content_locale' => 'en']);

        $this->category = Category::create(['name' => ['en' => 'List Laptops'], 'slug' => 'list-laptops', 'enabled' => true]);
        $this->feature = Feature::create(['name' => ['en' => 'List make'], 'code' => 'list_make', 'purpose' => 'brand', 'input_type' => 'select']);
        $this->apple = FeatureValue::create(['feature_id' => $this->feature->id, 'value' => ['en' => 'Apple'], 'slug' => 'list-apple']);

        $product = Product::create(['sku' => 'FPL-1', 'name' => ['en' => 'Air'], 'slug' => 'fpl-1', 'price' => 1000_00, 'stock' => 5, 'status' => 'active']);
        $product->categories()->attach($this->category->id);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $this->feature->id, 'feature_value_id' => $this->apple->id]);
    }

    protected function page(string $slug, array $extra = []): FilterPage
    {
        $values = [$this->feature->id => [$this->apple->id]];

        return FilterPage::create($extra + [
            'category_id' => $this->category->id,
            'slug' => $slug,
            'name' => ['en' => 'Page '.$slug],
            'values' => FilterPage::canonicalValues($values),
            'fingerprint' => FilterPage::fingerprintFor($this->category->id, $values).$slug,
            'enabled' => true,
            'in_sitemap' => true,
            'in_list' => true,
        ]);
    }

    public function test_search_and_tabs_narrow_the_list(): void
    {
        $this->page('alpha-zz', ['products_count' => 3]);
        $this->page('beta', ['enabled' => false, 'products_count' => 0]);

        $this->actingAs($this->admin)->get('http://localhost/admin/catalog/filter-pages?q=zz')
            ->assertOk()->assertSee('Page alpha-zz')->assertDontSee('Page beta');
        $this->get('http://localhost/admin/catalog/filter-pages?tab=off&category='.$this->category->id)
            ->assertSee('Page beta')->assertDontSee('Page alpha-zz');
        $this->get('http://localhost/admin/catalog/filter-pages?tab=empty&category='.$this->category->id)
            ->assertSee('Page beta')->assertDontSee('Page alpha-zz');
    }

    public function test_status_in_place_and_bulk_delete_leaves_redirects(): void
    {
        $one = $this->page('one');
        $two = $this->page('two');

        $this->actingAs($this->admin)
            ->patch("http://localhost/admin/catalog/filter-pages/{$one->id}/status", ['enabled' => 0], ['HX-Request' => 'true'])
            ->assertOk()->assertSee('filter-page-status-'.$one->id, false);
        $this->assertFalse($one->fresh()->enabled);

        $path = $two->path();
        $this->post('http://localhost/admin/catalog/filter-pages/bulk', ['action' => 'delete', 'ids' => [$two->id]])->assertRedirect();
        $this->assertNull(FilterPage::find($two->id));
        $this->assertNotNull(app(RedirectManager::class)->resolve($path));
    }

    public function test_saving_by_hand_counts_the_products_and_close_goes_back(): void
    {
        $page = $this->page('counted', ['products_count' => 0]);
        $fields = [
            'category_id' => $this->category->id, 'slug' => 'counted', 'name' => 'Counted',
            'values' => [$this->feature->id => [$this->apple->id]], 'enabled' => 1,
        ];

        $this->actingAs($this->admin)->put("http://localhost/admin/catalog/filter-pages/{$page->id}", $fields)
            ->assertRedirect(route('admin.catalog.filter-pages.edit', $page));
        $this->assertSame(1, $page->fresh()->products_count);

        $this->put("http://localhost/admin/catalog/filter-pages/{$page->id}", $fields + ['after_save' => 'close'])
            ->assertRedirect(route('admin.catalog.filter-pages.index'));
    }

    public function test_a_full_run_recounts_the_pages_no_rule_reached(): void
    {
        $page = $this->page('by-hand', ['products_count' => 0]);

        app(Generator::class)->run();

        $this->assertSame(1, $page->fresh()->products_count);
    }

    public function test_rules_list_status_in_place_and_bulk(): void
    {
        $rule = FilterRule::create([
            'name' => 'List rule ZZ', 'feature_id' => $this->feature->id, 'category_ids' => [$this->category->id],
            'with_children' => false, 'slug_template' => '{filter}', 'min_products' => 1,
            'in_sitemap' => true, 'in_list' => true, 'enabled' => true,
        ]);

        $this->actingAs($this->admin)->get('http://localhost/admin/catalog/filter-rules?q=ZZ')
            ->assertOk()->assertSee('List rule ZZ')->assertSee('List Laptops');
        $this->patch("http://localhost/admin/catalog/filter-rules/{$rule->id}/status", ['enabled' => 0], ['HX-Request' => 'true'])
            ->assertOk()->assertSee('filter-rule-status-'.$rule->id, false);
        $this->assertFalse($rule->fresh()->enabled);

        $this->post('http://localhost/admin/catalog/filter-rules/bulk', ['action' => 'enable', 'ids' => [$rule->id]])->assertRedirect();
        $this->assertTrue($rule->fresh()->enabled);
    }
}
