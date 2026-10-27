<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Filters\FilterPages;
use Torgash\Catalog\Filters\Generator;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\FilterRule;
use Torgash\Catalog\Models\Product;

/**
 * A price range as the fourth thing a published address can stand for
 * (PLAN §30.14) — "Budget laptops" is a category plus `price_max`, exactly the
 * way "Apple laptops" is a category plus a characteristic value.
 *
 * What is worth pinning: the page narrows by the SAME comparison the sidebar
 * uses (never a second formula that could drift), canonicalisation is by
 * EXACT boundary rather than "close enough" (a narrower or wider range is a
 * different, genuinely different set of products), the page's own bound shows
 * in the boxes so clearing them is a working way off the page, and the
 * generator's own axis never invents a boundary from the catalogue.
 */
class FilterPagePriceRangeTest extends TestCase
{
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => ['en' => 'Price pages'], 'slug' => 'price-pages', 'enabled' => true,
        ]);
    }

    public function test_a_page_of_a_price_ceiling_narrows_like_the_boxes_do(): void
    {
        $this->product('FPR-1', 'Cheap one', 200_00);
        $this->product('FPR-2', 'Dear one', 900_00);

        $page = $this->page(null, 300_00, 'budget');

        $this->get('http://localhost'.$page->path())->assertOk()
            ->assertSee('Cheap one')
            ->assertDontSee('Dear one');
    }

    /** The line the whole table exists for, now reachable by typing a price. */
    public function test_a_listing_with_the_same_price_typed_canonicalises_onto_the_page(): void
    {
        $this->product('FPR-3', 'Cheap one', 200_00);

        $page = $this->page(null, 300_00, 'budget');

        $this->get('http://localhost/catalog/price-pages?price_max=300')->assertOk()
            ->assertSee('rel="canonical" href="http://localhost'.$page->path().'"', false);
    }

    /**
     * A DIFFERENT boundary is a DIFFERENT, genuinely narrower or wider set of
     * products — "up to 299" is not "up to 300" — so a soft match here would
     * misdirect a crawler exactly the way an unknown characteristic value
     * would (§102's argument, extended to price).
     */
    public function test_a_different_boundary_does_not_canonicalise_onto_the_page(): void
    {
        $this->product('FPR-4', 'Cheap one', 200_00);

        $this->page(null, 300_00, 'budget');

        $this->get('http://localhost/catalog/price-pages?price_max=299')->assertOk()
            ->assertSee('rel="canonical" href="http://localhost/catalog/price-pages"', false);
    }

    /** The page's own bound shows in the boxes — the way off it is typing over them. */
    public function test_the_pages_own_bound_shows_in_the_price_boxes(): void
    {
        $this->product('FPR-5', 'Cheap one', 200_00);

        $page = $this->page(null, 300_00, 'budget');

        $body = $this->get('http://localhost'.$page->path())->assertOk()->getContent();

        $this->assertStringContainsString('name="price_max" value="300"', $body);

        // ...but a visitor already narrowing further is not silently
        // overwritten by the address they are standing on.
        $body = $this->get('http://localhost'.$page->path().'?price_max=250')->assertOk()->getContent();
        $this->assertStringContainsString('name="price_max" value="250"', $body);
    }

    /** Both bounds join the fingerprint only when set, so an old row hashes unchanged. */
    public function test_the_price_bounds_are_part_of_the_fingerprint_only_when_set(): void
    {
        $this->assertSame(
            $this->category->id.'|',
            FilterPage::fingerprintFor($this->category->id, [], null, [], null, null),
        );

        $this->assertNotSame(
            FilterPage::fingerprintFor($this->category->id, [], null, [], null, 30000_00),
            FilterPage::fingerprintFor($this->category->id, [], null, [], null, 29999_00),
        );
    }

    public function test_the_admin_form_writes_a_price_only_page(): void
    {
        $this->actingAs($this->admin());

        $this->post('http://localhost/admin/catalog/filter-pages', [
            'category_id' => $this->category->id,
            'name' => 'Budget',
            'price_max' => '300',
            'enabled' => '1',
            'in_sitemap' => '1',
        ])->assertRedirect();

        $page = FilterPage::where('category_id', $this->category->id)->firstOrFail();

        $this->assertNull($page->price_min);
        $this->assertSame(300_00, $page->price_max);
    }

    public function test_the_admin_form_refuses_a_lower_bound_above_the_upper_one(): void
    {
        $this->actingAs($this->admin());

        $this->post('http://localhost/admin/catalog/filter-pages', [
            'category_id' => $this->category->id,
            'name' => 'Backwards',
            'price_min' => '600',
            'price_max' => '300',
            'enabled' => '1',
        ])->assertSessionHasErrors('price_min');

        $this->assertSame(0, FilterPage::count());
    }

    /** A rule writes one page per bracket, never computed from the catalogue. */
    public function test_a_rule_makes_one_page_per_bracket(): void
    {
        $this->product('FPR-6', 'Cheap', 200_00);
        $this->product('FPR-7', 'Middling', 450_00);
        $this->product('FPR-8', 'Dear', 900_00);

        $rule = FilterRule::create([
            'name' => 'Price brackets',
            'category_ids' => [$this->category->id],
            'with_children' => false,
            'price_bounds' => [
                ['min' => null, 'max' => 300_00],
                ['min' => 300_00, 'max' => 600_00],
                ['min' => 600_00, 'max' => null],
            ],
            'slug_template' => '{price_min}-{price_max}',
            'min_products' => 1,
            'in_sitemap' => true,
            'enabled' => true,
        ]);

        $this->assertSame(FilterRule::AXIS_PRICE, $rule->axis());

        $counts = (new Generator)->run($rule);

        $this->assertSame(3, $counts['created']);
        // Str::slug() strips a leading/trailing hyphen, which is exactly what
        // an open-ended bracket's template ("" or "-300") produces.
        $this->assertSame(['300', '300-600', '600'], FilterPage::orderBy('position')->pluck('slug')->all());

        $cheap = FilterPage::where('slug', '300')->firstOrFail();
        $this->assertNull($cheap->price_min);
        $this->assertSame(300_00, $cheap->price_max);
        $this->assertSame(1, $cheap->products_count);

        $dear = FilterPage::where('slug', '600')->firstOrFail();
        $this->assertSame(600_00, $dear->price_min);
        $this->assertNull($dear->price_max);
        $this->assertSame(1, $dear->products_count);

        // A second run adds nothing it already made.
        $second = (new Generator)->run($rule);
        $this->assertSame(0, $second['created']);
        $this->assertSame(3, FilterPage::count());
    }

    public function test_the_rule_form_parses_the_boundaries_text(): void
    {
        $this->actingAs($this->admin());

        $this->post('http://localhost/admin/catalog/filter-rules', [
            'name' => 'Price brackets',
            'axis' => 'price',
            'price_bounds_text' => "-300\n300-600\n600-\n",
            'category_ids' => [$this->category->id],
            'min_products' => '1',
            'enabled' => '1',
            'in_sitemap' => '1',
            'in_list' => '1',
        ])->assertRedirect();

        $rule = FilterRule::where('name', 'Price brackets')->firstOrFail();

        $this->assertSame(FilterRule::AXIS_PRICE, $rule->axis());
        $this->assertSame([
            ['min' => null, 'max' => 300_00],
            ['min' => 300_00, 'max' => 600_00],
            ['min' => 600_00, 'max' => null],
        ], $rule->priceBounds());

        // ...and the edit screen shows back exactly what made it, the same
        // idiom `place_ids` already reads back as a picker's own chips.
        $this->assertSame("-300\n300-600\n600-", $rule->priceBoundsText());
    }

    public function test_the_rule_form_refuses_a_line_that_is_not_a_boundary(): void
    {
        $this->actingAs($this->admin());

        $this->post('http://localhost/admin/catalog/filter-rules', [
            'name' => 'Broken',
            'axis' => 'price',
            'price_bounds_text' => 'not a number',
            'category_ids' => [$this->category->id],
            'enabled' => '1',
        ])->assertSessionHasErrors('price_bounds_text');

        $this->assertSame(0, FilterRule::count());
    }

    /** No name typed falls back to the words the page stands for. */
    public function test_an_unnamed_price_page_names_itself_after_the_bound(): void
    {
        $upTo = $this->page(null, 300_00, 'up-to-300');
        $this->assertSame('up to 300', $upTo->title());

        $from = $this->page(300_00, null, 'from-300');
        $this->assertSame('from 300', $from->title());

        $between = $this->page(200_00, 600_00, 'between');
        $this->assertSame('200–600', $between->title());
    }

    /** @param  int|null  $min  minor units — write it as e.g. `300_00` for $300, the convention this suite already uses for money */
    protected function page(?int $min, ?int $max, string $slug): FilterPage
    {
        $page = FilterPage::create([
            'category_id' => $this->category->id,
            'slug' => $slug,
            'values' => [],
            'price_min' => $min,
            'price_max' => $max,
            'fingerprint' => FilterPage::fingerprintFor($this->category->id, [], null, [], $min, $max),
            'enabled' => true,
            'in_sitemap' => true,
        ]);

        app(FilterPages::class)->flush();

        return $page;
    }

    protected function product(string $sku, string $name, int $price): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => $price, 'stock' => 5, 'status' => 'active',
        ]);

        $product->categories()->attach($this->category->id);

        return $product;
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
