<?php

namespace Tests\Feature\Menus;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Blocks\Models\Block;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Menus\CategoryCounts;
use Torgash\Menus\MenuBuilder;
use Torgash\Menus\Models\Menu;
use Torgash\Menus\Models\MenuItem;
use Torgash\Menus\Placements;

/**
 * The menu's admin screen (PLAN §39.7): the tree rearranged by dragging or by
 * the arrows, the live preview of a category item, and where a menu stands.
 */
class MenuAdminTest extends TestCase
{
    protected Menu $menu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->menu = Menu::create(['code' => 'ma-test', 'name' => ['en' => 'Admin test'], 'enabled' => true]);
        $this->actingAs(User::role('admin')->firstOrFail());
    }

    protected function item(string $name, ?MenuItem $parent = null, int $sort = 0): MenuItem
    {
        return $this->menu->items()->create([
            'kind' => 'link', 'url' => '/'.strtolower($name), 'name' => ['en' => $name],
            'parent_id' => $parent?->id, 'sort' => $sort, 'enabled' => true,
        ]);
    }

    /** @return array<string, list<string>> parent name ('' = root) => children's names in order */
    protected function shape(): array
    {
        $rows = $this->menu->items()->get();
        $names = $rows->mapWithKeys(fn (MenuItem $i) => [$i->id => $i->name['en']]);
        $out = [];

        foreach ($rows as $row) {
            $out[$row->parent_id ? $names[$row->parent_id] : ''][] = $row->name['en'];
        }

        ksort($out);

        return $out;
    }

    public function test_dragging_saves_the_whole_tree(): void
    {
        $a = $this->item('A', sort: 10);
        $b = $this->item('B', sort: 20);
        $c = $this->item('C', sort: 30);

        $this->post(route('admin.menus.items.arrange', $this->menu), ['items' => [
            $a->id => ['parent' => 0, 'sort' => 20],
            $c->id => ['parent' => $a->id, 'sort' => 10],
            $b->id => ['parent' => 0, 'sort' => 10],
        ]], ['X-Requested-With' => 'XMLHttpRequest'])->assertNoContent();

        $this->assertSame(['' => ['B', 'A'], 'A' => ['C']], $this->shape());
    }

    /** A drop the script got wrong must not cut a branch off the tree. */
    public function test_arranging_refuses_a_loop_and_a_stranger(): void
    {
        $a = $this->item('A', sort: 10);
        $b = $this->item('B', $a);
        $stranger = Menu::create(['code' => 'ma-other', 'name' => ['en' => 'Other'], 'enabled' => true])
            ->items()->create(['kind' => 'link', 'url' => '/x', 'name' => ['en' => 'X'], 'enabled' => true]);

        $this->post(route('admin.menus.items.arrange', $this->menu), ['items' => [
            $a->id => ['parent' => $b->id, 'sort' => 10],
        ]])->assertStatus(422);

        $this->post(route('admin.menus.items.arrange', $this->menu), ['items' => [
            $a->id => ['parent' => $stranger->id, 'sort' => 10],
        ]])->assertStatus(422);

        $this->assertSame(['' => ['A'], 'A' => ['B']], $this->shape());
    }

    /** The arrows: what moves an item with no script and from the keyboard. */
    public function test_the_arrows_move_one_step_at_a_time(): void
    {
        $a = $this->item('A', sort: 10);
        $b = $this->item('B', sort: 20);
        $this->item('C', sort: 30);

        $move = fn (MenuItem $item, string $direction) => $this->post(route('admin.menus.items.move', [$this->menu, $item]), ['direction' => $direction])->assertRedirect();

        $move($b, 'up');
        $this->assertSame(['' => ['B', 'A', 'C']], $this->shape());

        $move($a, 'in');
        $this->assertSame(['' => ['B', 'C'], 'B' => ['A']], $this->shape(), 'in = last child of the item above');

        $move($a, 'out');
        $this->assertSame(['' => ['B', 'A', 'C']], $this->shape(), 'out = right after its old parent');

        $move($a, 'down');
        $this->assertSame(['' => ['B', 'C', 'A']], $this->shape());

        $move($b, 'in');
        $this->assertSame(['' => ['B', 'C', 'A']], $this->shape(), 'the first row has nothing above to go into');
    }

    /**
     * The preview names every subcategory the item reaches, and says why the
     * ones it leaves out are left out — decided by the storefront's own sift.
     */
    public function test_the_category_preview_explains_what_is_left_out(): void
    {
        $root = Category::create(['name' => ['en' => 'Wardrobe'], 'slug' => 'ma-wardrobe', 'enabled' => true]);
        $coats = Category::create(['name' => ['en' => 'Coats'], 'slug' => 'ma-coats', 'parent_id' => $root->id, 'enabled' => true]);
        Category::create(['name' => ['en' => 'Scarves'], 'slug' => 'ma-scarves', 'parent_id' => $root->id, 'enabled' => true]);
        $hats = Category::create(['name' => ['en' => 'Hats'], 'slug' => 'ma-hats', 'parent_id' => $root->id, 'enabled' => true]);
        app(CategoryTree::class)->flush();

        foreach ([$coats, $hats] as $i => $category) {
            Product::create(['sku' => "MA-{$i}", 'name' => ['en' => "P{$i}"], 'slug' => "ma-p{$i}", 'price' => 100, 'stock' => 3, 'status' => 'active'])
                ->categories()->sync([$category->id]);
        }

        $response = $this->get(route('admin.menus.items.preview', $this->menu).'?'.http_build_query([
            'source_category_id' => $root->id,
            'options' => ['depth' => 1, 'show_when' => 'orderable', 'exclude' => [$hats->id]],
        ]))->assertOk();

        $response->assertSeeInOrder(['Coats', '1', 'Scarves', 'empty by the rule', 'Hats', 'excluded on this item']);
        $response->assertSee('2 products');
    }

    public function test_a_plain_category_item_counts_nothing(): void
    {
        $root = Category::create(['name' => ['en' => 'Plain'], 'slug' => 'ma-plain', 'enabled' => true]);
        Category::create(['name' => ['en' => 'Child'], 'slug' => 'ma-plain-child', 'parent_id' => $root->id, 'enabled' => true]);
        app(CategoryTree::class)->flush();
        $this->menu->items()->create(['kind' => 'category', 'source_category_id' => $root->id, 'name' => [], 'enabled' => true, 'options' => ['depth' => 1, 'show_when' => 'always']]);

        Cache::flush();
        app(CategoryCounts::class)->flush();
        app(MenuBuilder::class)->flush();

        DB::enableQueryLog();
        $nodes = app(MenuBuilder::class)->nodes('ma-test');
        $log = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertSame('Child', $nodes[0]->children[0]->label);
        $this->assertFalse($log->contains(fn (string $sql) => str_contains($sql, 'm_catalog_category_product')), 'no rule, no counter, no counting');
    }

    /** Where a menu stands is read from the blocks and the theme settings that name it. */
    public function test_where_a_menu_stands(): void
    {
        Block::create(['zone' => 'storefront.header.top_start', 'type' => 'menu', 'title' => 'MA block', 'settings' => ['menu' => 'ma-test'], 'enabled' => false]);

        $places = app(Placements::class)->of('ma-test');
        $this->assertCount(1, $places);
        $this->assertSame(['block', 'MA block', true], [$places[0]['kind'], $places[0]['label'], $places[0]['off']]);

        // The seeded bottom bar is named by the default theme's own setting.
        $this->assertContains('theme', array_column(app(Placements::class)->of('tabbar'), 'kind'));

        $this->get(route('admin.menus.index'))->assertOk()->assertSee('MA block');
        $this->get(route('admin.menus.items', $this->menu))->assertOk()->assertSee('Where it stands')->assertSee('MA block');
    }
}
