<?php

namespace Tests\Feature\Menus;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Blocks\Models\Block;
use Torgash\Blocks\ZoneRenderer;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Menus\CategoryCounts;
use Torgash\Menus\MenuBuilder;
use Torgash\Menus\Models\Menu;
use Torgash\Menus\Models\MenuItem;
use Torgash\Menus\Node;

/**
 * Menus as the page draws them (PLAN §39): kinds, a category that opens into
 * its subcategories and keeps only those with something to buy, display
 * rules, the active entry, and the Menu block.
 */
class MenuBuilderTest extends TestCase
{
    protected Menu $menu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->menu = Menu::create(['code' => 'mb-test', 'name' => ['en' => 'Test'], 'enabled' => true]);
    }

    protected function category(string $slug, ?int $parent = null, array $attributes = []): Category
    {
        $category = Category::create($attributes + ['name' => ['en' => ucfirst($slug)], 'slug' => "mb-{$slug}", 'parent_id' => $parent, 'enabled' => true]);
        app(CategoryTree::class)->flush();

        return $category;
    }

    protected function product(string $sku, Category $category, array $attributes = []): Product
    {
        $product = Product::create($attributes + [
            'sku' => $sku,
            'name' => ['en' => "Product {$sku}"],
            'slug' => 'mb-'.strtolower($sku),
            'price' => 100_00,
            'stock' => 5,
            'status' => 'active',
        ]);
        $product->categories()->sync([$category->id]);

        return $product;
    }

    protected function item(array $attributes): MenuItem
    {
        return $this->menu->items()->create($attributes + ['name' => [], 'enabled' => true, 'sort' => 0]);
    }

    /** @return list<Node> */
    protected function nodes(): array
    {
        Cache::flush();
        app(CategoryCounts::class)->flush();
        app(MenuBuilder::class)->flush();

        return app(MenuBuilder::class)->nodes('mb-test');
    }

    /** @param list<Node> $nodes */
    protected function labels(array $nodes): array
    {
        return array_map(fn (Node $n) => $n->label, $nodes);
    }

    public function test_a_category_opens_into_its_subcategories_as_deep_as_asked(): void
    {
        $root = $this->category('electronics');
        $audio = $this->category('audio', $root->id);
        $this->category('headphones', $audio->id);

        $this->item(['kind' => 'category', 'source_category_id' => $root->id, 'options' => ['depth' => 1]]);
        $shallow = $this->nodes();

        $this->assertSame(['Electronics'], $this->labels($shallow));
        $this->assertSame(['Audio'], $this->labels($shallow[0]->children));
        $this->assertSame([], $shallow[0]->children[0]->children, 'one level means one level');

        MenuItem::query()->where('menu_id', $this->menu->id)->update(['options' => json_encode(['depth' => 2])]);
        $deep = $this->nodes();

        $this->assertSame(['Headphones'], $this->labels($deep[0]->children[0]->children));
    }

    /**
     * The owner's rule: a subcategory is shown only when something in its
     * WHOLE branch can be bought — a preorder counts, a sold-out product does
     * not, and "on the shelf" refuses the preorder too.
     */
    public function test_availability_rules_keep_only_branches_with_something_to_buy(): void
    {
        $root = $this->category('shop');
        $soldOut = $this->category('sold-out', $root->id);
        $preorder = $this->category('preorder', $root->id);
        $deep = $this->category('deep', $root->id);
        $deepLeaf = $this->category('deep-leaf', $deep->id);
        $empty = $this->category('empty', $root->id);

        $this->product('MB-SOLD', $soldOut, ['stock' => 0, 'availability_status' => 'out_of_stock']);
        $this->product('MB-PRE', $preorder, ['stock' => 0, 'availability_status' => 'preorder']);
        $this->product('MB-DEEP', $deepLeaf);

        $item = $this->item(['kind' => 'category', 'source_category_id' => $root->id, 'options' => ['depth' => 1, 'show_when' => 'always']]);
        $this->assertSame(['Sold-out', 'Preorder', 'Deep', 'Empty'], $this->labels($this->nodes()[0]->children));

        $item->update(['options' => ['depth' => 1, 'show_when' => 'listed']]);
        $this->assertSame(['Sold-out', 'Preorder', 'Deep'], $this->labels($this->nodes()[0]->children));

        $item->update(['options' => ['depth' => 1, 'show_when' => 'orderable']]);
        $this->assertSame(['Preorder', 'Deep'], $this->labels($this->nodes()[0]->children), 'counted through the branch: Deep holds nothing itself');

        $item->update(['options' => ['depth' => 1, 'show_when' => 'in_stock']]);
        $this->assertSame(['Deep'], $this->labels($this->nodes()[0]->children));
    }

    public function test_an_item_empty_by_its_rule_can_hide_itself(): void
    {
        $root = $this->category('nothing-here');
        $this->product('MB-GONE', $root, ['stock' => 0, 'availability_status' => 'out_of_stock']);

        $item = $this->item(['kind' => 'category', 'source_category_id' => $root->id, 'options' => ['show_when' => 'orderable']]);
        $this->assertCount(1, $this->nodes(), 'kept unless the shop asked for it to go');

        $item->update(['options' => ['show_when' => 'orderable', 'hide_empty' => true]]);
        $this->assertSame([], $this->nodes());
    }

    /** A product filed in two subcategories of one branch is one product of that branch. */
    public function test_counts_are_distinct_through_the_branch(): void
    {
        $root = $this->category('counted');
        $a = $this->category('a', $root->id);
        $b = $this->category('b', $root->id);

        $this->product('MB-BOTH', $a)->categories()->sync([$a->id, $b->id]);
        $this->product('MB-ONE', $b);

        $this->item(['kind' => 'category', 'source_category_id' => $root->id, 'options' => ['counts' => true]]);
        $node = $this->nodes()[0];

        $this->assertSame(2, $node->count);
        $this->assertSame([1, 2], array_map(fn (Node $n) => $n->count, $node->children));
    }

    public function test_replace_limit_exclude_and_everything_in(): void
    {
        $root = $this->category('dept');
        foreach (['one', 'two', 'three', 'four'] as $slug) {
            $this->category($slug, $root->id);
        }
        $two = Category::where('slug', 'mb-two')->first();

        $item = $this->item(['kind' => 'category', 'source_category_id' => $root->id, 'options' => ['replace' => true, 'exclude' => [$two->id]]]);
        $this->assertSame(['One', 'Three', 'Four'], $this->labels($this->nodes()), 'the children stand where the item was');

        $item->update(['options' => ['limit' => 2, 'all_link' => true]]);
        $children = $this->nodes()[0]->children;

        $this->assertSame(['all', 'category', 'category', 'more'], array_map(fn (Node $n) => $n->kind, $children));
        $this->assertStringContainsString('2', $children[3]->label);
        $this->assertSame(route('catalog.category', 'mb-dept'), $children[3]->href);
    }

    /** "The whole catalogue" is a category item with no category: the top categories. */
    public function test_the_whole_catalogue_is_the_top_categories(): void
    {
        $this->category('top-level');

        $this->item(['kind' => 'category', 'source_category_id' => null, 'options' => ['replace' => true, 'depth' => 1]]);

        $this->assertContains('Top-level', $this->labels($this->nodes()));
    }

    public function test_a_shop_page_is_drawn_by_name_and_vanishes_with_its_owner(): void
    {
        $this->item(['kind' => 'page', 'target' => 'route:catalog.brands.index', 'sort' => 1]);
        $this->item(['kind' => 'page', 'target' => 'route:nobody.answers', 'sort' => 2]);

        $nodes = $this->nodes();

        $this->assertSame(['Brands'], $this->labels($nodes));
        $this->assertSame(route('catalog.brands.index'), $nodes[0]->href);
        $this->assertSame('tag', $nodes[0]->icon, 'the page\'s own icon unless one is typed');
    }

    public function test_typed_name_icon_badge_and_manual_children(): void
    {
        $parent = $this->item(['kind' => 'heading', 'name' => ['en' => 'For customers'], 'icon' => 'info', 'badge' => ['en' => 'New'], 'badge_style' => 'error']);
        $this->item(['kind' => 'link', 'url' => '/delivery', 'name' => ['en' => 'Delivery'], 'parent_id' => $parent->id]);
        $this->item(['kind' => 'link', 'url' => '/x', 'name' => ['en' => 'Unknown icon'], 'icon' => 'no-such-icon', 'parent_id' => $parent->id, 'sort' => 5]);

        $node = $this->nodes()[0];

        $this->assertSame('For customers', $node->label);
        $this->assertNull($node->href);
        $this->assertSame('info', $node->icon);
        $this->assertSame('New', $node->badge);
        $this->assertSame('error', $node->badgeStyle);
        $this->assertSame(['Delivery', 'Unknown icon'], $this->labels($node->children));
        $this->assertNull($node->children[1]->icon, 'an unknown icon draws nothing rather than throwing');
    }

    /** Core's display rules, the same as a block's — and an unknown kind fails closed. */
    public function test_display_rules_decide_who_sees_an_item(): void
    {
        $this->item(['kind' => 'link', 'url' => '/a', 'name' => ['en' => 'English only'], 'rules' => [['kind' => 'locale', 'operator' => 'in', 'value' => ['en']]]]);
        $this->item(['kind' => 'link', 'url' => '/b', 'name' => ['en' => 'Russian only'], 'rules' => [['kind' => 'locale', 'operator' => 'in', 'value' => ['ru']]]]);
        $this->item(['kind' => 'link', 'url' => '/c', 'name' => ['en' => 'Ghost rule'], 'rules' => [['kind' => 'no-such-kind', 'operator' => 'in', 'value' => [1]]]]);
        $this->item(['kind' => 'link', 'url' => '/d', 'name' => ['en' => 'Off'], 'enabled' => false]);

        app()->setLocale('en');

        $this->assertSame(['English only'], $this->labels($this->nodes()));
    }

    /** The longest address the page is under wins, and its ancestors are the trail. */
    public function test_the_page_being_read_is_marked(): void
    {
        $root = $this->category('marked');
        $this->category('inner', $root->id);

        $this->item(['kind' => 'page', 'target' => 'route:catalog.index', 'sort' => 1]);
        $this->item(['kind' => 'category', 'source_category_id' => $root->id, 'sort' => 2]);

        $this->get(route('catalog.category', 'mb-inner'));
        $nodes = $this->nodes();

        $this->assertFalse($nodes[0]->active);
        $this->assertTrue($nodes[1]->trail);
        $this->assertTrue($nodes[1]->children[0]->active);
    }

    /** One menu with several category items asks the database a fixed number of times. */
    public function test_several_category_items_cost_one_counts_query(): void
    {
        foreach (['q1', 'q2', 'q3'] as $slug) {
            $category = $this->category($slug);
            $this->category($slug.'-child', $category->id);
            $this->item(['kind' => 'category', 'source_category_id' => $category->id, 'options' => ['show_when' => 'orderable', 'counts' => true]]);
        }

        Cache::flush();
        app(CategoryCounts::class)->flush();
        app(MenuBuilder::class)->flush();

        DB::enableQueryLog();
        app(MenuBuilder::class)->nodes('mb-test');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(6, $queries, 'menu + items + categories + counts, whatever the number of items');
    }

    public function test_the_menu_block_draws_a_menu_in_its_look(): void
    {
        $this->item(['kind' => 'link', 'url' => '/delivery', 'name' => ['en' => 'Delivery']]);

        foreach (['inline', 'dropdown', 'list', 'columns'] as $look) {
            app(MenuBuilder::class)->flush();
            $block = new Block(['type' => 'menu', 'zone' => 'storefront.header.top_start', 'settings' => ['menu' => 'mb-test', 'look' => $look, 'heading' => 'Help']]);

            $html = app(ZoneRenderer::class)->block($block);

            $this->assertStringContainsString('torgash-menu-'.$look, $html);
            $this->assertStringContainsString('href="/delivery"', $html);
        }

        $missing = new Block(['type' => 'menu', 'settings' => ['menu' => 'no-such-menu', 'look' => 'inline']]);
        $this->assertSame('', trim(app(ZoneRenderer::class)->block($missing)));
    }

    /** A shortcut is a live entry a module keeps: "Catalogue" opens the drawer, an unknown one is not drawn. */
    public function test_a_shortcut_is_what_a_module_registered(): void
    {
        $this->item(['kind' => 'shortcut', 'target' => 'catalog', 'sort' => 1]);
        $this->item(['kind' => 'shortcut', 'target' => 'compare', 'sort' => 2, 'note' => ['en' => 'Side by side']]);
        $this->item(['kind' => 'shortcut', 'target' => 'nobody-answers', 'sort' => 3]);

        $nodes = $this->nodes();

        $this->assertCount(2, $nodes);
        $this->assertTrue($nodes[0]->opensDrawer());
        $this->assertSame(route('catalog.index'), $nodes[0]->href, 'without a script it is still the way to the catalogue');
        $this->assertSame('compare', $nodes[1]->key);
        $this->assertSame('Side by side', $nodes[1]->note);
    }

    /** The phone's looks draw the same nodes: panels for the drawer, rows with the small line. */
    public function test_the_phone_looks_draw_levels_and_notes(): void
    {
        $root = $this->category('phones');
        $this->category('cases', $root->id);
        $this->item(['kind' => 'category', 'source_category_id' => $root->id, 'options' => ['depth' => 1], 'sort' => 1]);
        $this->item(['kind' => 'link', 'url' => '/sale', 'name' => ['en' => 'Sale'], 'note' => ['en' => 'Products on sale'], 'sort' => 2]);

        $drawer = app(ZoneRenderer::class)->block(new Block(['type' => 'menu', 'settings' => ['menu' => 'mb-test', 'look' => 'drawer', 'depth' => 2]]));
        $this->assertStringContainsString('torgash-menu-drawer', $drawer);
        $this->assertStringContainsString('path.push(', $drawer, 'a chevron into the panel of Phones');
        $this->assertStringContainsString('Cases', $drawer);

        app(MenuBuilder::class)->flush();
        $rows = app(ZoneRenderer::class)->block(new Block(['type' => 'menu', 'settings' => ['menu' => 'mb-test', 'look' => 'rows']]));
        $this->assertStringContainsString('Products on sale', $rows);
        $this->assertStringNotContainsString('path.push(', $rows, 'rows are one level');
    }

    /**
     * The bottom bar is the menu the theme setting names, and its shortcuts'
     * counters keep the ids an add refreshes out of band.
     */
    public function test_the_bottom_bar_is_a_menu(): void
    {
        $this->get('http://localhost/')->assertOk()
            ->assertSee('id="tabbar-badge-cart"', false)
            ->assertSee('id="tabbar-badge-wishlist"', false)
            ->assertDontSee('id="tabbar-badge-compare"', false);

        $this->item(['kind' => 'shortcut', 'target' => 'compare', 'sort' => 1]);
        $this->item(['kind' => 'page', 'target' => 'route:catalog.brands.index', 'icon' => 'tag', 'sort' => 2]);
        $storefront = \App\Models\Storefront::query()->orderBy('id')->firstOrFail();
        app(\App\Core\Themes\ThemeSettings::class)->save($storefront, ['tabbar_menu' => 'mb-test']);

        $this->get('http://localhost/')->assertOk()
            ->assertSee('id="tabbar-badge-compare"', false)
            ->assertSee(route('catalog.brands.index'), false)
            ->assertDontSee('id="tabbar-badge-cart"', false);
    }

    /** Comparison is offered to menus but kept out of the automatic bar, whose five places are taken. */
    public function test_an_auto_false_shortcut_stays_out_of_the_automatic_bar(): void
    {
        $storefront = \App\Models\Storefront::query()->orderBy('id')->firstOrFail();
        app(\App\Core\Themes\ThemeSettings::class)->save($storefront, ['tabbar_menu' => '']);

        $this->get('http://localhost/')->assertOk()
            ->assertSee('id="tabbar-badge-cart"', false)
            ->assertDontSee('id="tabbar-badge-compare"', false);
    }

    /** The header's strip is a zone now, and the theme fills it with the "top" menu. */
    public function test_the_header_draws_the_top_strip_menu(): void
    {
        $this->get('/')->assertOk()->assertSee('torgash-menu-inline', false);
    }

    /** A page draws six menus or more; together they cost two queries, not two each. */
    public function test_every_menu_of_the_page_is_read_in_two_queries(): void
    {
        $this->item(['kind' => 'link', 'url' => '/a', 'name' => ['en' => 'First menu row']]);
        $other = Menu::create(['code' => 'mb-other', 'name' => ['en' => 'Other'], 'enabled' => true]);
        $other->items()->create(['kind' => 'link', 'url' => '/b', 'name' => ['en' => 'Second menu row'], 'enabled' => true, 'sort' => 0]);
        Menu::create(['code' => 'mb-off', 'name' => ['en' => 'Off'], 'enabled' => false]);
        app(MenuBuilder::class)->flush();

        DB::enableQueryLog();
        $builder = app(MenuBuilder::class);
        $this->assertSame(['First menu row'], $this->labels($builder->nodes('mb-test')));
        $this->assertSame(['Second menu row'], $this->labels($builder->nodes('mb-other')));
        $this->assertFalse($builder->exists('mb-off'));
        $this->assertSame([], $builder->nodes('mb-off'));
        $menuQueries = array_filter(DB::getQueryLog(), fn (array $q) => str_contains($q['query'], 'm_menu'));
        DB::disableQueryLog();

        $this->assertCount(2, $menuQueries);
    }
}
