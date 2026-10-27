<?php

namespace Tests\Feature\Catalog;

use Tests\TestCase;
use Torgash\Blocks\BlockTypes;
use Torgash\Blocks\Models\Block;
use Torgash\Blocks\ZoneRenderer;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Selections\Selection;
use Torgash\Catalog\Selections\Sources;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Catalog\Support\Labels;
use Torgash\Promotions\Models\Promotion;

/**
 * "Some products, here" as a content block (PLAN §31.4) — where they come
 * from, in what order, and how several of them become the home page's tabs.
 */
class ProductBlockTest extends TestCase
{
    protected function product(string $sku, array $attributes = []): Product
    {
        return Product::create($attributes + [
            'sku' => $sku,
            'name' => ['en' => "Product {$sku}"],
            'slug' => 'pb-'.strtolower($sku),
            'price' => 100_00,
            'stock' => 5,
            'status' => 'active',
        ]);
    }

    protected function category(string $slug, ?int $parent = null, bool $enabled = true): Category
    {
        $category = Category::create(['name' => ['en' => ucfirst($slug)], 'slug' => "pb-{$slug}", 'parent_id' => $parent, 'enabled' => $enabled]);
        app(CategoryTree::class)->flush();

        return $category;
    }

    /** @return list<string> */
    protected function skus(array $settings): array
    {
        return app(Selection::class)->products($settings)->pluck('sku')->all();
    }

    /**
     * "Similar": what shares one of the page product's OWN categories — not
     * the product itself, not its variations, not a product one level up —
     * and nothing at all on a page that has no product.
     */
    public function test_similar_is_the_page_products_neighbours(): void
    {
        $phones = $this->category('phones-sim');
        $parent = $this->category('electronics-sim');

        $page = $this->product('PB-PAGE', ['variation_group' => 'pb-page']);
        $page->categories()->sync([$phones->id]);
        $this->product('PB-NEIGHBOUR')->categories()->sync([$phones->id]);
        $this->product('PB-VARIANT', ['variation_group' => 'pb-page'])->categories()->sync([$phones->id]);
        $this->product('PB-UPSTAIRS')->categories()->sync([$parent->id]);

        $similar = app(Selection::class)->products(['source' => 'similar'], ['product' => $page])->pluck('sku')->all();

        $this->assertSame(['PB-NEIGHBOUR'], $similar);
        $this->assertSame([], $this->skus(['source' => 'similar']), 'no product on the page, nothing similar');
    }

    /** "Viewed recently": newest first, the page's own product left out, and nothing at all when the shop switched it off. */
    public function test_viewed_recently_is_newest_first_and_can_be_switched_off(): void
    {
        $first = $this->product('PB-SEEN-1');
        $second = $this->product('PB-SEEN-2');
        $third = $this->product('PB-SEEN-3');

        $this->get('http://localhost/product/'.$first->slug)->assertOk();
        $this->get('http://localhost/product/'.$second->slug)->assertOk();
        $this->get('http://localhost/product/'.$third->slug)->assertOk();
        $this->get('http://localhost/product/'.$first->slug)->assertOk();

        $viewed = fn () => app(Selection::class)->products(['source' => 'viewed'], ['product' => $second])->pluck('sku')->all();
        $this->assertSame(['PB-SEEN-1', 'PB-SEEN-3'], $viewed(), 'looked at again comes back to the front; the page product is left out');

        app(\App\Core\Settings\SettingsManager::class)->set('torgash.catalog', 'recently_viewed', false);
        $this->assertSame([], $viewed());
    }

    /** A category means itself and everything under it, here as everywhere. */
    public function test_a_category_source_takes_its_subcategories_and_nothing_else(): void
    {
        $parent = $this->category('computers');
        $child = $this->category('laptops', $parent->id);
        $other = $this->category('garden');

        $this->product('PB-TOP')->categories()->sync([$parent->id]);
        $this->product('PB-CHILD')->categories()->sync([$child->id]);
        $this->product('PB-GARDEN')->categories()->sync([$other->id]);
        $this->product('PB-HIDDEN', ['status' => 'hidden'])->categories()->sync([$child->id]);

        $found = $this->skus(['source' => "category:{$parent->id}", 'sort' => 'name', 'limit' => 10]);
        sort($found);

        $this->assertSame(['PB-CHILD', 'PB-TOP'], $found, 'the subtree, and a hidden product stays hidden');
    }

    /** A source whose argument means nothing any more shows nothing — never the whole catalogue. */
    public function test_a_source_that_no_longer_means_anything_narrows_to_nothing(): void
    {
        $this->product('PB-ANY');
        $off = $this->category('switched-off', enabled: false);
        $this->product('PB-OFF')->categories()->sync([$off->id]);

        $this->assertSame([], $this->skus(['source' => "category:{$off->id}"]));
        $this->assertSame([], $this->skus(['source' => 'category:999999']));
        $this->assertSame([], $this->skus(['source' => 'label:999999']));
        $this->assertSame([], $this->skus(['source' => 'flag:nobody_answers']));
        $this->assertSame([], $this->skus(['source' => 'no_such_kind']));
        $this->assertSame([], $this->skus(['source' => 'manual', 'products' => '']));
    }

    /** Typed order is kept, and the limit keeps the FIRST ones typed. */
    public function test_hand_picked_products_come_in_the_order_they_were_typed(): void
    {
        $this->product('PB-A');
        $this->product('PB-B');
        $this->product('PB-C');
        $this->product('PB-GONE', ['status' => 'disabled']);

        $this->assertSame(
            ['PB-C', 'PB-A'],
            $this->skus(['source' => 'manual', 'products' => "PB-C\nPB-GONE\n\n  PB-A \nPB-NOBODY\nPB-B", 'limit' => 2]),
        );
    }

    /** Commas and lines both separate, and a picked sort overrides the typed order. */
    public function test_hand_picked_products_can_be_sorted_instead(): void
    {
        $this->product('PB-DEAR', ['price' => 900_00]);
        $this->product('PB-CHEAP', ['price' => 1_00]);
        $this->product('PB-MID', ['price' => 50_00]);

        $settings = ['source' => 'manual', 'products' => "PB-DEAR, PB-MID\nPB-CHEAP", 'limit' => 10];

        $this->assertSame(['PB-DEAR', 'PB-MID', 'PB-CHEAP'], $this->skus($settings));
        $this->assertSame(['PB-CHEAP', 'PB-MID', 'PB-DEAR'], $this->skus($settings + ['sort' => 'price_asc']));
    }

    /** A label shows what it is on: put there by hand, or matched by its rules. */
    public function test_a_label_source_is_its_manual_products_and_its_rule_matches(): void
    {
        $label = Label::create([
            'name' => ['en' => 'Premium'], 'name_for_admin' => 'Premium', 'section_title' => ['en' => 'Premium picks'],
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'price', 'operator' => 'gte', 'value' => '800000']]],
        ]);
        app(Labels::class)->flush();

        $byHand = $this->product('PB-BYHAND', ['price' => 1_00]);
        $label->products()->attach($byHand->id);
        $this->product('PB-RULE', ['price' => 9_000_000_00]);
        $this->product('PB-NEITHER', ['price' => 2_00]);

        $found = $this->skus(['source' => "label:{$label->id}", 'limit' => 48]);

        $this->assertContains('PB-BYHAND', $found);
        $this->assertContains('PB-RULE', $found);
        $this->assertNotContains('PB-NEITHER', $found);
        $this->assertSame('Premium picks', app(Selection::class)->heading(['source' => "label:{$label->id}"]));

        $label->update(['enabled' => false]);
        app(Labels::class)->flush();

        $this->assertSame([], $this->skus(['source' => "label:{$label->id}"]), 'a switched-off label shows nothing');
    }

    /** The heading names the source unless the block says otherwise; the link is the source's page. */
    public function test_heading_and_link_come_from_the_source(): void
    {
        $category = $this->category('kettles');
        $selection = app(Selection::class);

        $this->assertSame('Kettles', $selection->heading(['source' => "category:{$category->id}"]));
        $this->assertSame('Boil', $selection->heading(['source' => "category:{$category->id}", 'heading' => 'Boil']));
        $this->assertStringEndsWith('/catalog/pb-kettles', (string) $selection->url(['source' => "category:{$category->id}"]));
        $this->assertNull($selection->url(['source' => 'manual']));
    }

    /** The "on sale" filter as a source, through the flag's own SQL. */
    public function test_a_filter_flag_is_a_source(): void
    {
        $this->product('PB-SALE', ['price' => 80_00, 'list_price' => 100_00]);
        $this->product('PB-FULL', ['price' => 80_00]);

        $found = $this->skus(['source' => 'flag:on_sale', 'limit' => 48]);

        $this->assertContains('PB-SALE', $found);
        $this->assertNotContains('PB-FULL', $found);
    }

    /** Promotions add their own source without the catalogue naming them — and an ended sale shows nothing. */
    public function test_a_running_promotion_is_a_source_and_a_stopped_one_is_empty(): void
    {
        $category = $this->category('promo-shelf');
        $this->product('PB-PROMO')->categories()->sync([$category->id]);
        $this->product('PB-NOT-PROMO');

        $promotion = Promotion::create(['name' => ['en' => 'Deal'], 'zone' => 'catalog', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => [['kind' => 'category', 'operator' => 'cat_in', 'ids' => [$category->id]]]],
            'discount_type' => 'percent', 'discount_value' => 10,
        ]);

        $this->assertContains("promotion:{$promotion->id}", array_column(app(Sources::class)->options(), 'value'));
        $this->assertSame(['PB-PROMO'], $this->skus(['source' => "promotion:{$promotion->id}"]));
        $this->assertSame('Deal', app(Selection::class)->heading(['source' => "promotion:{$promotion->id}"]));

        $promotion->update(['enabled' => false]);

        $this->assertSame([], $this->skus(['source' => "promotion:{$promotion->id}"]));
    }

    /** The select's options are built when asked, and a value nobody offered is not stored. */
    public function test_the_source_is_stored_only_as_something_on_offer(): void
    {
        $category = $this->category('stored');

        $kept = BlockTypes::sanitize('products', ['source' => "category:{$category->id}", 'limit' => 500]);
        $this->assertSame("category:{$category->id}", $kept['source']);
        $this->assertSame(Selection::MAX_LIMIT, $kept['limit']);

        $invented = BlockTypes::sanitize('products', ['source' => 'category:999999', 'sort' => 'by_mood']);
        $this->assertSame('all', $invented['source']);
        $this->assertSame('', $invented['sort']);
    }

    /** Blocks side by side with one word are ONE row of tabs; an empty one is not a tab. */
    public function test_neighbouring_blocks_with_the_same_word_are_drawn_as_tabs(): void
    {
        $category = $this->category('tabbed');
        $this->product('PB-TAB')->categories()->sync([$category->id]);

        $block = fn (int $id, string $heading, string $source, ?string $tabs) => (new Block([
            'type' => 'products', 'tabs' => $tabs, 'enabled' => true,
            'settings' => ['heading' => $heading, 'source' => $source, 'limit' => 4],
        ]))->forceFill(['id' => $id]);

        $html = app(ZoneRenderer::class)->render([
            $block(901, 'First', "category:{$category->id}", 'home'),
            $block(902, 'Empty', 'category:999999', 'home'),
            $block(903, 'Second', 'all', 'home'),
        ]);

        $this->assertSame(1, substr_count($html, 'class="torgash-tabs"'));
        $this->assertStringContainsString('<span>First</span>', $html);
        $this->assertStringContainsString('<span>Second</span>', $html);
        $this->assertStringNotContainsString('Empty', $html, 'nothing to show is not a tab');
        $this->assertStringContainsString('name="block-tabs-901"', $html);
    }

    /** One member left is a heading over its content; a different word starts another row. */
    public function test_a_lone_tab_is_a_heading_and_another_word_is_another_row(): void
    {
        $block = fn (int $id, string $heading, ?string $tabs) => (new Block([
            'type' => 'products', 'tabs' => $tabs, 'enabled' => true,
            'settings' => ['heading' => $heading, 'source' => 'all', 'limit' => 2],
        ]))->forceFill(['id' => $id]);

        $this->product('PB-LONE');

        $lone = app(ZoneRenderer::class)->render([$block(911, 'Only', 'home')]);
        $this->assertStringNotContainsString('class="torgash-tabs"', $lone);
        $this->assertStringContainsString('<h2 class="text-lg font-semibold mb-4">Only</h2>', $lone);

        $two = app(ZoneRenderer::class)->render([
            $block(912, 'A', 'one'), $block(913, 'B', 'one'),
            $block(914, 'C', 'two'), $block(915, 'D', 'two'),
            $block(916, 'Plain', null),
        ]);
        $this->assertSame(2, substr_count($two, 'class="torgash-tabs"'));
        $this->assertStringContainsString('<h2 class="text-lg font-semibold">Plain</h2>', $two);
    }

    /** The grid can hold some back for a "Show more" button; the carousel is one row. */
    public function test_layouts_render(): void
    {
        foreach (range(1, 5) as $i) {
            $this->product("PB-LAY-{$i}");
        }

        $grid = app(ZoneRenderer::class)->block(new Block([
            'type' => 'products', 'settings' => ['source' => 'manual', 'products' => 'PB-LAY-1,PB-LAY-2,PB-LAY-3,PB-LAY-4,PB-LAY-5', 'reveal' => 2, 'limit' => 5],
        ]));
        $this->assertSame(3, substr_count($grid, 'x-show="shown > '), 'three of five wait for the button');
        $this->assertStringContainsString('Show more', $grid);

        $carousel = app(ZoneRenderer::class)->block(new Block([
            'type' => 'products', 'settings' => ['source' => 'manual', 'products' => 'PB-LAY-1,PB-LAY-2,PB-LAY-3', 'layout' => 'carousel'],
        ]));
        $this->assertSame(3, substr_count($carousel, 'carousel-item'));
    }

    /** The theme lays the home page's tabs out, and the page draws them. */
    public function test_the_home_page_draws_the_themes_product_tabs(): void
    {
        $this->product('PB-HOME', ['name' => ['en' => 'Home page kettle']]);

        $this->assertSame(
            ['Popular', 'New arrivals', 'On sale'],
            Block::where('zone', 'storefront.home.products')->orderBy('sort')->pluck('title')->all(),
        );
        $this->assertSame(['home'], Block::where('zone', 'storefront.home.products')->distinct()->pluck('tabs')->all());

        $this->get('http://localhost/')
            ->assertOk()
            ->assertSee('class="torgash-tabs"', false)
            ->assertSee('<span>New arrivals</span>', false)
            ->assertSee('Home page kettle');
    }
}
