<?php

namespace Tests\Feature\Catalog;

use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Selections\Sources;

/**
 * "A category AND a filter" as a set of products — a filter page, offered
 * among the product sources (a block, an option's set, a kit slot all read
 * them). Published or not: an unpublished page is a saved selection.
 */
class FilterPageSourceTest extends TestCase
{
    public function test_a_filter_page_is_a_set_of_its_category_and_values(): void
    {
        $pans = Category::create(['name' => ['en' => 'FPS Pans'], 'slug' => 'fps-pans']);
        $knives = Category::create(['name' => ['en' => 'FPS Knives'], 'slug' => 'fps-knives']);
        $material = Feature::create(['name' => ['en' => 'FPS Material'], 'code' => 'fps_material', 'purpose' => 'filter', 'input_type' => 'select']);
        $steel = FeatureValue::create(['feature_id' => $material->id, 'value' => ['en' => 'Steel']]);
        $wood = FeatureValue::create(['feature_id' => $material->id, 'value' => ['en' => 'Wood']]);

        $steelPan = $this->product('FPS-1', $pans, $steel);
        $this->product('FPS-2', $pans, $wood);
        $this->product('FPS-3', $knives, $steel); // steel, but another category

        $values = [$material->id => [$steel->id]];
        $page = FilterPage::create([
            'category_id' => $pans->id, 'slug' => 'steel',
            'values' => FilterPage::canonicalValues($values),
            'fingerprint' => FilterPage::fingerprintFor($pans->id, $values),
            'enabled' => false, // a saved selection, published nowhere
            'in_sitemap' => false,
        ]);

        $sources = app(Sources::class);
        $sources->flush();

        [$definition, $arg] = $sources->resolve('filter_page:'.$page->id);
        $ids = ($definition['apply'])(Product::query(), $arg, [])->pluck('id')->all();

        $this->assertSame([$steelPan->id], $ids);
        $this->assertNull(($definition['url'])($arg), 'an unpublished page has no address to link to');

        // It is offered in the admin's list of sets, with the label group
        // named as the word it is.
        $groups = collect($sources->options())->pluck('group')->unique()->all();
        $this->assertContains('Filter page', $groups);
        $this->assertSame('Product label', $sources->label($sources->all()['label']));
    }

    protected function product(string $sku, Category $category, FeatureValue $value): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku),
            'price' => 100_00, 'stock' => 4, 'status' => 'active',
        ]);
        $product->categories()->attach($category->id);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $value->feature_id, 'feature_value_id' => $value->id]);

        return $product;
    }
}
