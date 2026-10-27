<?php

namespace Tests\Feature\Catalog;

use App\Core\Display\DisplayRules;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\PriceType;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Catalog\Support\Labels;
use Torgash\Menus\Models\Menu;

/**
 * A category is its subcategories.
 *
 * Everything here failed before the tree had one place to live, and every
 * failure was silent. A shop keeps its products in the leaves, so the parent
 * category pages — "Electronics", "Home & kitchen" — rendered a perfectly
 * correct empty grid, and the sidebar listed only root categories, so the
 * second level of the catalogue was linked from no page at all: invisible to a
 * shopper and to a crawler alike. Meanwhile every rule that named a category
 * compared DIRECT membership, so "products in Electronics" governed nothing,
 * and the one switch that existed for it ("incl. subcategories", on label
 * rules) went exactly one level down.
 *
 * The rule now has one implementation, Support\CategoryTree, and these are the
 * questions it has to keep answering.
 */
class CategoryTreeTest extends TestCase
{
    protected Category $root;

    protected Category $middle;

    protected Category $leaf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = Category::create(['name' => ['en' => 'Electronics'], 'slug' => 'tree-electronics', 'enabled' => true, 'sort' => 1]);
        $this->middle = Category::create(['name' => ['en' => 'Computers'], 'slug' => 'tree-computers', 'enabled' => true, 'parent_id' => $this->root->id, 'sort' => 1]);
        $this->leaf = Category::create(['name' => ['en' => 'Laptops'], 'slug' => 'tree-laptops', 'enabled' => true, 'parent_id' => $this->middle->id, 'sort' => 1]);

        app(CategoryTree::class)->flush();
    }

    /** Three levels, not one — the shape of the bug the label switch had. */
    public function test_descendants_reach_the_bottom_of_the_tree(): void
    {
        $ids = app(CategoryTree::class)->descendants($this->root->id);

        $this->assertContains($this->root->id, $ids);
        $this->assertContains($this->middle->id, $ids);
        $this->assertContains($this->leaf->id, $ids);
    }

    /**
     * A parent chain that loops is a corrupt row, not an impossible one, and a
     * walk that trusts the data hangs the request instead of showing a page.
     */
    public function test_a_loop_in_the_tree_does_not_hang_the_walk(): void
    {
        // Straight to the column: the form refuses this, which is the point of
        // the other test — this one is about surviving a row that got in anyway.
        Category::whereKey($this->root->id)->update(['parent_id' => $this->leaf->id]);
        app(CategoryTree::class)->flush();

        $ids = app(CategoryTree::class)->descendants($this->root->id);

        $this->assertCount(3, $ids);
        $this->assertSame([$this->middle->id], array_slice(app(CategoryTree::class)->ancestors($this->leaf->id), 0, 1));
    }

    /** The picker: tree order, indented — never the flat table sorted by `sort`. */
    public function test_the_picker_lists_the_tree_in_order_and_indented(): void
    {
        $options = app(CategoryTree::class)->options();
        $ours = array_intersect_key($options, array_flip([$this->root->id, $this->middle->id, $this->leaf->id]));

        $this->assertSame([$this->root->id, $this->middle->id, $this->leaf->id], array_keys($ours));
        $this->assertSame('Electronics', $ours[$this->root->id]);
        $this->assertSame('— Computers', $ours[$this->middle->id]);
        $this->assertSame('— — Laptops', $ours[$this->leaf->id]);
    }

    /** An excluded branch takes its children with it — that is what stops a loop being offered. */
    public function test_an_excluded_branch_takes_its_children_with_it(): void
    {
        $options = app(CategoryTree::class)->options(exclude: [$this->middle->id]);

        $this->assertArrayHasKey($this->root->id, $options);
        $this->assertArrayNotHasKey($this->middle->id, $options);
        $this->assertArrayNotHasKey($this->leaf->id, $options);
    }

    /** The storefront half: the page a shop owner thought was broken. */
    public function test_a_parent_category_shows_what_is_underneath_it(): void
    {
        $product = $this->product('TREE-1', 'Deep laptop', $this->leaf);

        $this->get('http://localhost/catalog/'.$this->root->slug)->assertOk()
            ->assertSee($product->t('name'));
    }

    /** ...and its children are reachable, which is how anything finds them at all. */
    public function test_a_parent_category_links_its_children(): void
    {
        $body = $this->get('http://localhost/catalog/'.$this->root->slug)->assertOk()->getContent();

        $this->assertStringContainsString('/catalog/'.$this->middle->slug, $body);
    }

    /** The sidebar opens along the branch: a leaf's page shows its own level. */
    public function test_the_sidebar_opens_the_current_branch(): void
    {
        $body = $this->get('http://localhost/catalog/'.$this->leaf->slug)->assertOk()->getContent();

        foreach ([$this->root, $this->middle, $this->leaf] as $category) {
            $this->assertStringContainsString('/catalog/'.$category->slug, $body);
        }
    }

    /** A characteristic is attached to a branch and filters everything under it. */
    public function test_a_characteristic_attached_to_a_parent_filters_the_child(): void
    {
        $feature = Feature::create([
            'name' => ['en' => 'Screen size'], 'code' => 'tree_screen', 'purpose' => 'filter', 'input_type' => 'select',
        ]);
        $value = FeatureValue::create(['feature_id' => $feature->id, 'value' => ['en' => '15 inch']]);
        $feature->categories()->attach($this->root->id);

        $product = $this->product('TREE-2', 'Filtered laptop', $this->leaf);

        // The product has to CARRY the value, or the panel rightly hides a
        // characteristic there is nothing here to filter by — see
        // StorefrontController::narrowToWhatIsHere().
        ProductFeature::create([
            'product_id' => $product->id,
            'feature_id' => $feature->id,
            'feature_value_id' => $value->id,
        ]);

        $this->get('http://localhost/catalog/'.$this->leaf->slug)->assertOk()
            ->assertSee('Screen size');
    }

    /** The rule half, on the switch that used to go one level: a grandchild counts. */
    public function test_a_label_rule_on_a_grandparent_reaches_a_grandchild(): void
    {
        $product = $this->product('TREE-3', 'Labelled laptop', $this->leaf);

        $label = Label::create([
            'name' => ['en' => 'Deal'],
            'name_for_admin' => 'Deal',
            'color' => 'primary',
            'enabled' => true,
            'conditions' => [
                'match' => 'all',
                'rules' => [['field' => 'category', 'operator' => 'in', 'value' => [$this->root->id]]],
            ],
        ]);
        app(Labels::class)->flush();

        $matched = Label::forProducts(collect([$product->fresh()]));

        $this->assertTrue(
            collect($matched[$product->id] ?? [])->contains(fn (Label $l) => $l->id === $label->id),
            'a label rule naming the top of the tree did not reach a product three levels down',
        );
    }

    /** And the same for a display rule, which decides whether a block is drawn at all. */
    public function test_a_display_rule_on_a_parent_matches_a_product_below_it(): void
    {
        $product = $this->product('TREE-4', 'Shown laptop', $this->leaf);

        $rules = [['kind' => 'product_category', 'operator' => 'in', 'value' => [$this->root->id]]];

        $this->assertTrue(app(DisplayRules::class)->passes($rules, ['product' => $product->fresh()->load('categories')], 'all'));
    }

    /**
     * Every screen that offers categories still renders.
     *
     * Shallow on purpose and worth its seconds: the fix changed what those
     * controllers hand their views — a collection of models became a map of
     * id => indented label — across four modules, and a picker that throws is
     * a screen nobody can open.
     */
    public function test_every_screen_that_offers_categories_still_renders(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $priceType = PriceType::create([
            'code' => 'tree-test', 'name' => ['en' => 'Tree test'], 'sort' => 0, 'enabled' => true,
        ]);
        $menu = Menu::create(['code' => 'tree-test', 'name' => ['en' => 'Tree test']]);

        foreach ([
            '/admin/catalog/categories',
            '/admin/catalog/categories/create',
            '/admin/catalog/categories/'.$this->middle->id.'/edit',
            '/admin/catalog/features/create',
            '/admin/catalog/labels/create',
            '/admin/catalog/products/create',
            '/admin/catalog/price-types/'.$priceType->id.'/rules/create',
            '/admin/menus/'.$menu->id.'/items/create',
            '/admin/motivation/create',
            '/admin/promotions/create',
            '/admin/automations/create',
        ] as $url) {
            $this->actingAs($user)->get('http://localhost'.$url)->assertOk();
        }
    }

    /** The form refuses a loop rather than leaving one to be survived. */
    public function test_a_category_cannot_be_moved_inside_its_own_branch(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)
            ->put('http://localhost/admin/catalog/categories/'.$this->root->id, [
                'name' => 'Electronics',
                'slug' => $this->root->slug,
                'parent_id' => $this->leaf->id,
                'enabled' => 1,
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertNull($this->root->fresh()->parent_id);
    }

    protected function product(string $sku, string $name, Category $category): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => 500_00, 'stock' => 3, 'status' => 'active',
        ]);

        $product->categories()->attach($category->id);

        return $product;
    }
}
