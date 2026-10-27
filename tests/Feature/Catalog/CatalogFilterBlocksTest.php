<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;

/**
 * The filter panel's blocks fold (`filter_open_blocks`), and a long one gets
 * a search box and scrolls inside itself (`filter_search_from`).
 */
class CatalogFilterBlocksTest extends TestCase
{
    protected Category $category;

    protected Feature $brand;

    protected Feature $colour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => ['en' => 'Blocks'], 'slug' => 'filter-blocks', 'enabled' => true]);
        $this->brand = Feature::create(['name' => ['en' => 'Maker'], 'code' => 'blocks_maker', 'purpose' => 'filter', 'input_type' => 'select']);
        $this->colour = Feature::create(['name' => ['en' => 'Shade'], 'code' => 'blocks_shade', 'purpose' => 'filter', 'input_type' => 'select']);

        // Twelve makers, one product each; one shade on all of them.
        $shade = FeatureValue::create(['feature_id' => $this->colour->id, 'value' => ['en' => 'Teal']]);

        foreach (range(1, 12) as $n) {
            $maker = FeatureValue::create(['feature_id' => $this->brand->id, 'value' => ['en' => 'Maker '.$n]]);
            $product = Product::create([
                'sku' => 'FB-'.$n, 'name' => ['en' => 'Thing '.$n], 'slug' => 'fb-'.$n,
                'price' => 100_00, 'stock' => 4, 'status' => 'active',
            ]);
            $product->categories()->attach($this->category->id);

            foreach ([$maker, $shade] as $value) {
                ProductFeature::create(['product_id' => $product->id, 'feature_id' => $value->feature_id, 'feature_value_id' => $value->id]);
            }
        }
    }

    public function test_every_block_starts_open_by_default(): void
    {
        $body = $this->get('http://localhost/catalog/filter-blocks')->assertOk()->getContent();

        $this->assertSame([true, true, true], $this->openness($body, ['price', 'f'.$this->brand->id, 'f'.$this->colour->id]));
    }

    /** Counted from the price; a block with a box ticked stays open regardless. */
    public function test_only_the_first_blocks_open_but_a_ticked_one_always_does(): void
    {
        app(SettingsManager::class)->set('torgash.catalog', 'filter_open_blocks', 'none');

        $body = $this->get('http://localhost/catalog/filter-blocks')->getContent();
        $this->assertSame([false, false, false], $this->openness($body, ['price', 'f'.$this->brand->id, 'f'.$this->colour->id]));

        $teal = FeatureValue::where('feature_id', $this->colour->id)->first();
        $body = $this->get('http://localhost/catalog/filter-blocks?feature['.$this->colour->id.'][]='.$teal->id)->getContent();
        $this->assertSame([false, false, true], $this->openness($body, ['price', 'f'.$this->brand->id, 'f'.$this->colour->id]));
    }

    public function test_a_long_block_gets_a_search_box_and_a_short_one_does_not(): void
    {
        $body = $this->get('http://localhost/catalog/filter-blocks')->getContent();

        $this->assertSame(1, substr_count($body, 'data-filter-search '));

        app(SettingsManager::class)->set('torgash.catalog', 'filter_search_from', 0);
        $this->assertStringNotContainsString('data-filter-search ', $this->get('http://localhost/catalog/filter-blocks')->getContent());
    }

    /** In a scrolled list what is in force is never below the fold. */
    public function test_ticked_values_come_first_in_a_long_block(): void
    {
        $last = FeatureValue::where('feature_id', $this->brand->id)->orderByDesc('id')->first();

        $this->get('http://localhost/catalog/filter-blocks?feature['.$this->brand->id.'][]='.$last->id)
            ->assertSeeInOrder(['Maker 12', 'Maker 1<', 'Maker 2<'], false);
    }

    /** @return list<bool> */
    protected function openness(string $body, array $keys): array
    {
        return array_map(function (string $key) use ($body) {
            $this->assertMatchesRegularExpression('/data-filter-block="'.$key.'"/', $body);
            preg_match('/data-filter-block="'.$key.'"\s*([^>]*)>/', $body, $match);

            return str_contains($match[1], 'open');
        }, $keys);
    }
}
