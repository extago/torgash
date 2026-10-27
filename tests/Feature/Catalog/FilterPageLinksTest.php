<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Locations\Models\Place;

/**
 * Which published pages the category actually LINKS to.
 *
 * "The page exists" and "the category advertises it" used to be one column, so
 * a shop publishing a page per town got a strip of two hundred buttons above
 * its grid and no way to thin it but taking live addresses down. Three
 * statements hold that apart, and each is a way the strip could go wrong:
 * `in_list` off leaves the address answering, the shop's limit bounds each row,
 * and the page being looked at is in its own row whatever either says — the
 * strip is how a visitor gets back out of it.
 */
class FilterPageLinksTest extends TestCase
{
    protected Category $category;

    protected Feature $feature;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => ['en' => 'Laptops'], 'slug' => 'link-laptops', 'enabled' => true,
        ]);
        $this->feature = Feature::create([
            'name' => ['en' => 'Make'], 'code' => 'link_make', 'purpose' => 'brand', 'input_type' => 'select',
        ]);

        app(SettingsManager::class)->set('torgash.catalog', 'filter_page_links', 12);
    }

    public function test_a_page_out_of_the_list_still_answers_at_its_own_address(): void
    {
        $this->product('LNK-1', 'Air', $apple = $this->value('Apple', 'link-apple'));
        $this->product('LNK-1D', 'Inspiron', $dell = $this->value('Dell', 'link-dell'));

        $linked = $this->page('apple', [$apple]);
        $quiet = $this->page('dell', [$dell], ['in_list' => false]);

        $category = $this->get('http://localhost/catalog/link-laptops');
        $category->assertOk();
        $category->assertSee($linked->path());
        $category->assertDontSee($quiet->path());

        // Not advertised is a different answer from not published.
        $this->get('http://localhost'.$quiet->path())->assertOk();
    }

    public function test_the_shop_bounds_each_row_and_towns_are_counted_apart(): void
    {
        app(SettingsManager::class)->set('torgash.catalog', 'filter_page_links', 2);

        $filters = collect(range(1, 3))->map(function (int $i) {
            $value = $this->value("Make {$i}", "link-make-{$i}");
            $this->product("LNK-2-{$i}", "Laptop {$i}", $value);

            return $this->page("f{$i}", [$value], ['position' => $i]);
        });
        $towns = collect(range(1, 3))->map(fn (int $i) => $this->page("t{$i}", [], [
            'position' => $i, 'place_id' => $this->place("Town {$i}")->id,
        ]));

        $page = $this->get('http://localhost/catalog/link-laptops');

        // Two of each: a town may not push a narrowing page out of the strip,
        // and the limit is a row's, not the card's.
        $page->assertSee($filters[0]->path());
        $page->assertSee($filters[1]->path());
        $page->assertDontSee($filters[2]->path());
        $page->assertSee($towns[0]->path());
        $page->assertSee($towns[1]->path());
        $page->assertDontSee($towns[2]->path());
    }

    public function test_a_page_that_did_not_fit_is_in_the_strip_while_it_is_the_one_being_read(): void
    {
        $this->product('LNK-3A', 'Air', $apple = $this->value('Apple', 'link-apple'));
        $this->product('LNK-3D', 'Inspiron', $dell = $this->value('Dell', 'link-dell'));

        app(SettingsManager::class)->set('torgash.catalog', 'filter_page_links', 1);

        $first = $this->page('first', [$apple], ['position' => 1]);
        $cut = $this->page('cut', [$dell], ['position' => 2]);

        $page = $this->get('http://localhost'.$cut->path());

        // Its own path is in the head whatever happens (canonical, hreflang)
        // and `aria-current` is on the sidebar's category too, so what is read
        // here is the strip's own marker: the filled-in button.
        $page->assertOk();
        $page->assertSee('btn btn-sm normal-case btn-primary', false);
        $page->assertDontSee($first->path());
    }

    /** Out of the list means out of it everywhere, its own address included. */
    public function test_a_page_out_of_the_list_is_not_drawn_on_its_own_address(): void
    {
        $this->product('LNK-4A', 'Air', $apple = $this->value('Apple', 'link-apple'));
        $this->product('LNK-4D', 'Inspiron', $dell = $this->value('Dell', 'link-dell'));

        $listed = $this->page('listed', [$apple]);
        $quiet = $this->page('quiet', [$dell], ['in_list' => false]);

        $page = $this->get('http://localhost'.$quiet->path());

        $page->assertOk();
        $page->assertDontSee('btn btn-sm normal-case btn-primary', false);
        // The strip is still the category's own, and still drawn.
        $page->assertSee($listed->path());
    }

    protected function value(string $name, string $slug): FeatureValue
    {
        return FeatureValue::create([
            'feature_id' => $this->feature->id,
            'value' => ['en' => $name],
            'slug' => $slug,
            'code' => $slug,
        ]);
    }

    /** @param  array<int, FeatureValue>  $values */
    protected function page(string $slug, array $values, array $extra = []): FilterPage
    {
        $set = [];

        foreach ($values as $value) {
            $set[$value->feature_id][] = $value->id;
        }

        $set = FilterPage::canonicalValues($set);
        $placeId = $extra['place_id'] ?? null;

        $page = FilterPage::create([
            'category_id' => $this->category->id,
            'slug' => $slug,
            'values' => $set,
            'fingerprint' => FilterPage::fingerprintFor($this->category->id, $set, $placeId),
            'enabled' => true,
            'in_sitemap' => true,
        ] + $extra);

        $page->setRelation('category', $this->category);

        return $page;
    }

    protected function place(string $name): Place
    {
        return Place::create([
            'country' => 'UA',
            'name' => ['en' => $name],
            'type' => 'city',
            'enabled' => true,
            'source' => 'manual',
            'population' => 1000,
        ]);
    }

    protected function product(string $sku, string $name, ?FeatureValue $value = null): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => 1000_00, 'stock' => 5, 'status' => 'active',
        ]);

        $product->categories()->attach($this->category->id);

        if ($value) {
            ProductFeature::create([
                'product_id' => $product->id,
                'feature_id' => $value->feature_id,
                'feature_value_id' => $value->id,
            ]);
        }

        return $product;
    }
}
