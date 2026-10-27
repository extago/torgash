<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryNav;
use Torgash\Catalog\Support\CategoryTree;

/**
 * The category list beside the catalogue: the whole tree or only the
 * visitor's branch, counts that count a subtree once, and empty categories
 * hidden without ever hiding where the visitor stands.
 */
class CategoryNavTest extends TestCase
{
    protected Category $root;

    protected Category $phones;

    protected Category $apple;

    protected Category $htc;

    protected Category $empty;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = Category::create(['name' => ['en' => 'Electronics'], 'slug' => 'nav-electronics', 'enabled' => true, 'sort' => 1]);
        $this->phones = Category::create(['name' => ['en' => 'Phones'], 'slug' => 'nav-phones', 'enabled' => true, 'parent_id' => $this->root->id, 'sort' => 1]);
        $this->apple = Category::create(['name' => ['en' => 'Apple'], 'slug' => 'nav-apple', 'enabled' => true, 'parent_id' => $this->phones->id, 'sort' => 1]);
        $this->htc = Category::create(['name' => ['en' => 'HTC'], 'slug' => 'nav-htc', 'enabled' => true, 'parent_id' => $this->phones->id, 'sort' => 2]);
        $this->empty = Category::create(['name' => ['en' => 'Nokia'], 'slug' => 'nav-nokia', 'enabled' => true, 'parent_id' => $this->phones->id, 'sort' => 3]);

        app(CategoryTree::class)->flush();

        // One phone filed under BOTH leaves: the branch above holds it once.
        $this->product('NAV-1', $this->apple, $this->htc);
        $this->product('NAV-2', $this->apple);
        $this->product('NAV-3', $this->htc)->update(['status' => 'disabled']);
    }

    public function test_a_leaf_in_branch_mode_shows_the_way_up_and_its_neighbours(): void
    {
        $this->set('category_nav_mode', 'branch');

        $rows = $this->summary(app(CategoryNav::class)->rows($this->apple));

        $this->assertSame([
            ['All products', 'up'],
            ['Electronics', 'up'],
            ['Phones', 'up'],
            ['Apple', 'current'],
            ['HTC', 'item'],
            ['Nokia', 'item'],
        ], $rows);
    }

    public function test_a_parent_in_branch_mode_shows_itself_and_what_is_under_it(): void
    {
        $this->set('category_nav_mode', 'branch');
        $this->set('category_nav_all_link', false);

        $rows = app(CategoryNav::class)->rows($this->phones);

        $this->assertSame([['Electronics', 'up'], ['Phones', 'current'], ['Apple', 'item'], ['HTC', 'item'], ['Nokia', 'item']], $this->summary($rows));
        $this->assertSame(1, $rows[2]['depth']);
    }

    public function test_counts_take_the_subtree_once_and_only_listed_products(): void
    {
        $this->set('category_nav_mode', 'branch');
        $this->set('category_nav_counts', true);

        $counts = collect(app(CategoryNav::class)->rows($this->phones))
            ->filter(fn ($row) => $row['category'])
            ->mapWithKeys(fn ($row) => [$row['category']->slug => $row['count']])
            ->all();

        $this->assertSame(['nav-electronics' => 2, 'nav-phones' => 2, 'nav-apple' => 2, 'nav-htc' => 1, 'nav-nokia' => 0], $counts);
    }

    public function test_hiding_empty_categories_never_hides_the_one_the_visitor_is_in(): void
    {
        $this->set('category_nav_mode', 'branch');
        $this->set('category_nav_hide_empty', true);

        $rows = app(CategoryNav::class)->rows($this->empty);
        $names = array_column($this->summary($rows), 0);

        $this->assertContains('Nokia', $names);
        $this->assertContains('Apple', $names);
        $this->assertNull($rows[0]['count'], 'hiding empty ones does not switch the numbers on');

        $names = array_column($this->summary(app(CategoryNav::class)->rows($this->phones)), 0);
        $this->assertNotContains('Nokia', $names);
    }

    public function test_the_tree_mode_opens_the_visitors_branch_and_off_shows_nothing(): void
    {
        $rows = $this->summary(app(CategoryNav::class)->rows($this->apple));

        $this->assertContains(['Apple', 'current'], $rows);
        $this->assertContains(['HTC', 'item'], $rows);

        $this->set('category_nav_mode', 'off');
        $this->assertSame([], app(CategoryNav::class)->rows($this->apple));
    }

    public function test_the_page_draws_the_back_rows_and_the_counts(): void
    {
        $this->set('category_nav_mode', 'branch');
        $this->set('category_nav_counts', true);

        $this->get('http://localhost/catalog/nav-apple')
            ->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSeeInOrder(['Electronics', 'Phones', 'Apple', 'HTC']);
    }

    protected function set(string $key, mixed $value): void
    {
        app(SettingsManager::class)->set('torgash.catalog', $key, $value);
    }

    /** @return list<array{0: string, 1: string}> */
    protected function summary(array $rows): array
    {
        return array_map(fn ($row) => [$row['category'] ? $row['category']->t('name') : 'All products', $row['kind']], $rows);
    }

    protected function product(string $sku, Category ...$categories): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku),
            'price' => 500_00, 'stock' => 3, 'status' => 'active',
        ]);

        $product->categories()->attach(array_map(fn ($c) => $c->id, $categories));

        return $product;
    }
}
