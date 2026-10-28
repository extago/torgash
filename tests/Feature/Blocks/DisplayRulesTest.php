<?php

namespace Tests\Feature\Blocks;

use App\Core\Display\DisplayRules;
use App\Core\Display\RuleKinds;
use App\Models\CustomerGroup;
use App\Models\User;
use Tests\TestCase;
use Torgash\Blocks\Models\Block;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;

/**
 * When a block is shown — the engine, its registry, and the block using both.
 *
 * The registry is the point: "on wholesale products, to a signed-in customer,
 * where the pickup point has it" is one sentence whose halves belong to
 * different modules. Core owns the engine and the questions about page and
 * visitor; the catalogue registers the ones about products; neither knows the
 * other exists.
 */
class DisplayRulesTest extends TestCase
{
    /** Nothing asked, nothing to check. */
    public function test_a_block_with_no_rules_is_shown(): void
    {
        $this->assertTrue((new Block(['rules' => null]))->isVisible());
        $this->assertTrue(app(DisplayRules::class)->passes([]));
    }

    /** A rule whose kind nobody registered hides the thing rather than being ignored. */
    public function test_an_unknown_kind_fails_closed(): void
    {
        $rules = app(DisplayRules::class);

        $this->assertFalse($rules->passes([['kind' => 'module_is_gone', 'operator' => 'in', 'value' => [1]]]));
        $this->assertStringContainsString('module_is_gone', $rules->describe(['kind' => 'module_is_gone']));
    }

    /** ALL narrows, ANY widens — and both stop asking as soon as they know. */
    public function test_all_and_any_are_different_questions(): void
    {
        $rules = app(DisplayRules::class);

        $yes = ['kind' => 'visitor', 'operator' => 'guest'];
        $no = ['kind' => 'visitor', 'operator' => 'authenticated'];

        $this->assertFalse($rules->passes([$yes, $no], [], DisplayRules::MATCH_ALL));
        $this->assertTrue($rules->passes([$yes, $no], [], DisplayRules::MATCH_ANY));
    }

    /** Who is looking: core's own kinds, answered from the ambient context. */
    public function test_the_visitor_kinds_read_the_current_user(): void
    {
        $rules = app(DisplayRules::class);
        // The name is translatable JSONB, hence the map.

        $group = CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 5]);

        $this->assertTrue($rules->passes([['kind' => 'visitor', 'operator' => 'guest']]));

        $user = User::factory()->create(['customer_group_id' => $group->id]);
        $this->actingAs($user);

        $this->assertTrue($rules->passes([['kind' => 'visitor', 'operator' => 'authenticated']]));
        $this->assertTrue($rules->passes([['kind' => 'customer_group', 'operator' => 'in', 'value' => [$group->id]]]));
        $this->assertFalse($rules->passes([['kind' => 'customer_group', 'operator' => 'not_in', 'value' => [$group->id]]]));
    }

    /**
     * The catalogue's kinds, and the case that decides the whole design: the
     * same rule on a page with no product is a No, not a Yes.
     */
    public function test_a_product_rule_needs_a_product_and_says_no_without_one(): void
    {
        $category = Category::create(['name' => ['en' => 'Laptops'], 'slug' => 'rules-laptops']);
        $product = Product::create([
            'sku' => 'RULE-1', 'name' => ['en' => 'Ruled'], 'slug' => 'ruled',
            'price' => 10_00, 'stock' => 3, 'status' => 'active',
        ]);
        $product->categories()->sync([$category->id]);

        $rules = app(DisplayRules::class);
        $rule = ['kind' => 'product_category', 'operator' => 'in', 'value' => [$category->id]];

        $this->assertTrue($rules->passes([$rule], ['product' => $product->fresh()]));
        $this->assertFalse($rules->passes([$rule], []));

        // Stock is a question about the product too, and reads the live number.
        $this->assertTrue($rules->passes([['kind' => 'product_stock', 'operator' => 'in_stock']], ['product' => $product]));
        $product->update(['stock' => 0]);
        $this->assertTrue($rules->passes([['kind' => 'product_stock', 'operator' => 'out_of_stock']], ['product' => $product->fresh()]));
    }

    /**
     * The block asks the engine, and the answer moves with the visitor.
     *
     * Asserted on the model rather than through a page: the zone subscription
     * is built once at boot from the blocks that exist, so a block created
     * inside a test is not in it, and refreshing the application would drop the
     * transaction this test's rows live in. The render path is a single `if`
     * over exactly this method.
     */
    public function test_a_block_answers_for_itself(): void
    {
        $block = new Block([
            'zone' => 'catalog.product.price_extra',
            'type' => 'rich',
            'rules' => [['kind' => 'visitor', 'operator' => 'authenticated']],
            'rules_match' => DisplayRules::MATCH_ALL,
        ]);

        $this->assertFalse($block->isVisible());

        $this->actingAs(User::factory()->create());

        $this->assertTrue($block->isVisible());
    }

    /** The admin reads words, not a row of ids. */
    public function test_a_rule_describes_itself_with_names(): void
    {
        // The name is translatable JSONB, hence the map.

        $group = CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 5]);

        $described = app(DisplayRules::class)->describe([
            'kind' => 'customer_group', 'operator' => 'in', 'value' => [$group->id],
        ]);

        $this->assertStringContainsString('Wholesale', $described);
        $this->assertStringNotContainsString((string) $group->id, $described);
    }

    /** The picker indents a subcategory ("— — Duo"); a sentence names it. */
    public function test_a_subcategory_is_described_by_its_name_alone(): void
    {
        $phones = Category::create(['name' => ['en' => 'DR phones'], 'slug' => 'dr-phones', 'enabled' => true]);
        $duo = Category::create(['name' => ['en' => 'DR duo'], 'slug' => 'dr-duo', 'parent_id' => $phones->id, 'enabled' => true]);
        app(CategoryTree::class)->flush();

        $this->assertSame('— DR duo', RuleKinds::options('product_category')[$duo->id], 'the picker still indents');
        $this->assertSame('Product category — is in DR phones, DR duo', app(DisplayRules::class)->describe([
            'kind' => 'product_category', 'operator' => 'in', 'value' => [$phones->id, $duo->id],
        ]));
    }

    /**
     * Narrowed in SQL as far as the kinds can say it — only narrowed: a kind
     * that cannot (stock) leaves its products in for passes() to ask.
     */
    public function test_rules_narrow_a_product_query_as_far_as_their_kinds_can(): void
    {
        $phones = Category::create(['name' => ['en' => 'NR phones'], 'slug' => 'nr-phones', 'enabled' => true]);
        $duo = Category::create(['name' => ['en' => 'NR duo'], 'slug' => 'nr-duo', 'parent_id' => $phones->id, 'enabled' => true]);
        app(CategoryTree::class)->flush();
        $inDuo = Product::create(['sku' => 'NR-1', 'name' => ['en' => 'NR-1'], 'slug' => 'nr-1', 'price' => 100, 'stock' => 1, 'status' => 'active']);
        $inDuo->categories()->attach($duo->id);
        $elsewhere = Product::create(['sku' => 'NR-2', 'name' => ['en' => 'NR-2'], 'slug' => 'nr-2', 'price' => 100, 'stock' => 1, 'status' => 'active']);

        $narrowed = fn (array $rules, string $match = DisplayRules::MATCH_ALL) => app(DisplayRules::class)
            ->narrow(Product::query()->whereKey([$inDuo->id, $elsewhere->id]), $rules, $match)->orderBy('id')->pluck('id')->all();
        $inPhones = ['kind' => 'product_category', 'operator' => 'in', 'value' => [$phones->id]];
        $inStock = ['kind' => 'product_stock', 'operator' => 'in_stock', 'value' => null];

        $this->assertSame([$inDuo->id], $narrowed([$inPhones]), 'a category takes its subcategories');
        $this->assertSame([$elsewhere->id], $narrowed([['operator' => 'not_in'] + $inPhones]));
        $this->assertSame([$inDuo->id, $elsewhere->id], $narrowed([$inStock]));
        $this->assertSame([$inDuo->id], $narrowed([$inPhones, $inStock]));
        $this->assertSame([$inDuo->id, $elsewhere->id], $narrowed([$inPhones, $inStock], DisplayRules::MATCH_ANY), 'one that cannot narrow lets everything through');
        $this->assertSame([], $narrowed([$inPhones, ['kind' => 'module_is_gone', 'operator' => 'in', 'value' => [1]]]), 'all, with a kind nobody knows: nothing');
        $this->assertSame([$inDuo->id], $narrowed([$inPhones, ['kind' => 'module_is_gone', 'operator' => 'in', 'value' => [1]]], DisplayRules::MATCH_ANY));
    }

    /**
     * Every registered kind must be able to draw its own editor.
     *
     * The rule form resolves the options of EVERY kind at once, so one broken
     * resolver takes down the whole screen rather than its own row — which is
     * how a missing import in a module's registration went unnoticed until the
     * page 500'd. Cheap to check, and it grows with the registry by itself.
     */
    public function test_every_kind_can_resolve_its_own_editor(): void
    {
        foreach (RuleKinds::all() as $name => $definition) {
            $this->assertNotSame('', RuleKinds::label($name), "[$name] has no label");
            $this->assertNotEmpty($definition['operators'], "[$name] declares no operators");
            $this->assertContains($definition['value'], RuleKinds::VALUE_TYPES, "[$name] has an unknown value type");

            if ($definition['value'] === 'options') {
                // The call is the test: a bad resolver throws here.
                $this->assertIsArray(RuleKinds::options($name));
            }
        }
    }

    /** A module registers a kind and the engine can answer it — no core change. */
    public function test_a_module_can_register_its_own_kind(): void
    {
        RuleKinds::register('full_moon', [
            'label' => ['en' => 'The moon'],
            'operators' => ['is_full' => ['en' => 'is full']],
            'value' => 'none',
            'matches' => fn () => true,
        ]);

        try {
            $this->assertTrue(app(DisplayRules::class)->passes([['kind' => 'full_moon', 'operator' => 'is_full']]));
            $this->assertArrayHasKey('full_moon', RuleKinds::all());
        } finally {
            RuleKinds::forget('full_moon');
        }
    }
}
