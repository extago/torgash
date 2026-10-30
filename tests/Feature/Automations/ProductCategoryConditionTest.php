<?php

namespace Tests\Feature\Automations;

use Tests\TestCase;
use Torgash\Automations\Conditions;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleCondition;
use Torgash\Automations\RuleTemplate;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Checkout\Models\Order;

/**
 * "The order has an item from Laptops" — the one condition kind that needed the
 * catalogue as a source of names (§18's leftover). Membership is resolved from
 * each line's product when the rule runs, and a line whose product is gone
 * counts as being in no category.
 */
class ProductCategoryConditionTest extends TestCase
{
    private Category $laptops;

    private Category $phones;

    private Product $laptop;

    private Product $phone;

    private Product $loose;

    protected function setUp(): void
    {
        parent::setUp();

        $this->laptops = Category::create(['name' => ['en' => 'Laptops'], 'slug' => 'pc-laptops']);
        $this->phones = Category::create(['name' => ['en' => 'Phones'], 'slug' => 'pc-phones']);

        $this->laptop = $this->product('PC-L', $this->laptops);
        $this->phone = $this->product('PC-P', $this->phones);
        $this->loose = $this->product('PC-X', null);
    }

    public function test_has_any_is_true_when_one_line_matches(): void
    {
        $this->assertTrue($this->pass('cat_has_any', [$this->laptops->id], [$this->laptop, $this->phone]));
        $this->assertFalse($this->pass('cat_has_any', [$this->laptops->id], [$this->phone]));
        $this->assertFalse($this->pass('cat_has_any', [$this->laptops->id], [$this->loose]));
    }

    /**
     * A category IS its subtree, as everywhere in the engine: a rule on the
     * parent sees an item from a child category, and "has no item from" the
     * parent is false for it. Measured before the fix: a product in
     * "Laptops › Gaming" did not match a rule on "Laptops".
     */
    public function test_a_category_includes_its_subcategories(): void
    {
        $gaming = Category::create(['name' => ['en' => 'Gaming'], 'slug' => 'pc-gaming', 'parent_id' => $this->laptops->id]);
        $gamingLaptop = $this->product('PC-G', $gaming);
        app(CategoryTree::class)->flush();

        $this->assertTrue($this->pass('cat_has_any', [$this->laptops->id], [$gamingLaptop]));
        $this->assertFalse($this->pass('cat_has_none', [$this->laptops->id], [$gamingLaptop]));
        $this->assertTrue($this->pass('cat_all_from', [$this->laptops->id], [$this->laptop, $gamingLaptop]));
        // The other way round stays false: the child does not include the parent.
        $this->assertFalse($this->pass('cat_has_any', [$gaming->id], [$this->laptop]));
    }

    public function test_has_none_is_the_negation(): void
    {
        $this->assertTrue($this->pass('cat_has_none', [$this->laptops->id], [$this->phone, $this->loose]));
        $this->assertFalse($this->pass('cat_has_none', [$this->laptops->id], [$this->laptop, $this->phone]));
    }

    public function test_all_from_needs_every_line_covered_and_at_least_one_line(): void
    {
        $ids = [$this->laptops->id, $this->phones->id];

        $this->assertTrue($this->pass('cat_all_from', $ids, [$this->laptop, $this->phone]));
        // A line whose product is in none of the chosen categories fails it.
        $this->assertFalse($this->pass('cat_all_from', $ids, [$this->laptop, $this->loose]));
        // No lines is not "every line" — it is nothing to vouch for.
        $this->assertFalse($this->pass('cat_all_from', $ids, []));
    }

    public function test_a_deleted_product_line_is_in_no_category(): void
    {
        $order = $this->order([$this->laptop, $this->phone]);
        $this->phone->forceDelete();

        $this->assertFalse(Conditions::pass(
            [$this->condition('cat_all_from', [$this->laptops->id, $this->phones->id])],
            $order,
        ));
    }

    public function test_describe_prints_names_when_it_has_them_and_a_count_otherwise(): void
    {
        $condition = $this->condition('cat_has_any', [$this->laptops->id, $this->phones->id]);

        $this->assertSame(
            'Product category — has an item from Laptops, Phones',
            Conditions::describe($condition, [$this->laptops->id => 'Laptops', $this->phones->id => 'Phones']),
        );
        $this->assertStringContainsString('2 selected', Conditions::describe($condition));
    }

    public function test_an_exported_template_drops_the_shop_specific_category_ids(): void
    {
        $rule = Rule::create(['name' => 'Tag laptop orders', 'event' => 'checkout.order.changed', 'enabled' => true]);
        $rule->conditions()->create([
            'kind' => 'product_category',
            'operator' => 'cat_has_any',
            'value' => ['ids' => [$this->laptops->id]],
            'position' => 0,
        ]);

        $exported = RuleTemplate::export($rule->fresh('conditions'));

        $this->assertSame('product_category', $exported['conditions'][0]['kind']);
        $this->assertSame(['ids' => []], $exported['conditions'][0]['value']);
    }

    // ---------------------------------------------------------------- helpers

    private function product(string $sku, ?Category $category): Product
    {
        $product = Product::create([
            'sku' => $sku,
            'name' => ['en' => $sku],
            'slug' => strtolower($sku),
            'price' => 100_00,
            'stock' => 5,
            'status' => 'active',
        ]);

        if ($category) {
            $product->categories()->attach($category);
        }

        return $product;
    }

    /** @param  list<Product>  $products */
    private function order(array $products): Order
    {
        return new Order([
            'items' => array_map(fn (Product $p) => ['product_id' => $p->id, 'qty' => 1], $products),
        ]);
    }

    /** @param  list<int>  $ids */
    private function condition(string $operator, array $ids): RuleCondition
    {
        return new RuleCondition([
            'kind' => 'product_category',
            'operator' => $operator,
            'value' => ['ids' => $ids],
        ]);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<Product>  $products
     */
    private function pass(string $operator, array $ids, array $products): bool
    {
        return Conditions::pass([$this->condition($operator, $ids)], $this->order($products));
    }
}
