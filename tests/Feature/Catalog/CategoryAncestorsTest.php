<?php

namespace Tests\Feature\Catalog;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Support\CategoryTree;

/**
 * A category's chain of parents comes from the tree, read once per request.
 *
 * It was a query per level per question, and the question is asked per
 * PICTURE (a template for an image's alt text looks up the categories of the
 * product it shows): a live shop's product page spent 27-50 queries on it.
 */
class CategoryAncestorsTest extends TestCase
{
    public function test_the_chain_runs_from_the_root_down_to_the_parent(): void
    {
        [$top, $middle, $leaf] = $this->chain();

        $this->assertSame([$top->id, $middle->id], $this->ids($leaf->ancestors()));
        $this->assertSame([$top->id], $this->ids($middle->ancestors()));
        $this->assertSame([], $top->ancestors());
    }

    /** Every picture holds its own copy of the category, so the copies are what must not cost anything. */
    public function test_asking_for_many_copies_of_a_category_reads_the_tree_once(): void
    {
        [, , $leaf] = $this->chain();
        $copies = [Category::find($leaf->id), Category::find($leaf->id), Category::find($leaf->id)];
        app(CategoryTree::class)->flush();

        DB::enableQueryLog();

        foreach ($copies as $copy) {
            $this->assertCount(2, $copy->ancestors());
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries, 'the tree once, nothing per level or per copy');
    }

    /** As `$category->parent` read it: a deleted category is not there. */
    public function test_a_deleted_parent_ends_the_chain(): void
    {
        [$top, $middle, $leaf] = $this->chain();

        $middle->delete();

        $this->assertSame([], $leaf->fresh()->ancestors());
        $this->assertSame([$top->id], $this->ids($middle->ancestors()), 'a deleted category still knows where it stood');
    }

    /** A chain that loops is a corrupt row, not an impossible one: it used to hang the page. */
    public function test_a_chain_that_loops_comes_to_an_end(): void
    {
        [$top, , $leaf] = $this->chain();

        DB::table('m_catalog_categories')->where('id', $top->id)->update(['parent_id' => $leaf->id]);
        app(CategoryTree::class)->flush();

        $this->assertCount(3, $leaf->fresh()->ancestors());
    }

    /** The tree stays for the request: whoever has just moved a row is the next to ask. */
    public function test_a_category_moved_in_this_request_is_read_back_where_it_went(): void
    {
        [$top, $middle, $leaf] = $this->chain();
        $this->assertCount(2, $leaf->ancestors());

        $middle->update(['parent_id' => null]);
        $this->assertSame([$middle->id], $this->ids($leaf->fresh()->ancestors()));

        $leaf->update(['parent_id' => $top->id]);
        $this->assertSame([$top->id], $this->ids($leaf->ancestors()));
    }

    /** @return array{0: Category, 1: Category, 2: Category} */
    protected function chain(): array
    {
        $top = Category::create(['name' => ['en' => 'Top'], 'slug' => 'tree-top', 'enabled' => true]);
        $middle = Category::create(['name' => ['en' => 'Middle'], 'slug' => 'tree-middle', 'parent_id' => $top->id, 'enabled' => true]);
        $leaf = Category::create(['name' => ['en' => 'Leaf'], 'slug' => 'tree-leaf', 'parent_id' => $middle->id, 'enabled' => true]);

        return [$top, $middle, $leaf];
    }

    /**
     * @param  list<Category>  $categories
     * @return list<int>
     */
    protected function ids(array $categories): array
    {
        return array_map(fn (Category $category) => (int) $category->id, $categories);
    }
}
