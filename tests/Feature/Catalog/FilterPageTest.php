<?php

namespace Tests\Feature\Catalog;

use App\Core\Seo\RedirectManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Filters\FilterPages;
use Torgash\Catalog\Filters\Generator;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\FilterRule;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Seo\Models\Template;
use Torgash\Seo\Templates;

/**
 * A category plus a set of characteristic values, at an address of its own.
 *
 * What is pinned here is the half that is invisible when it breaks. A filter
 * page that renders is obvious; what is not obvious is that the FILTERED
 * LISTING showing the same products points its canonical at it — without that
 * line the table buys nothing, because the shop still publishes one indexable
 * address and an unbounded number of unindexable ones, which is the state this
 * whole phase exists to leave.
 *
 * The other invariant with teeth is that a published address is never
 * withdrawn: a thinning page leaves the sitemap, a deleted one leaves a 301,
 * and a second run of a generator adds nothing it already made.
 */
class FilterPageTest extends TestCase
{
    protected Category $category;

    protected Feature $feature;

    protected FeatureValue $apple;

    protected FeatureValue $asus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => ['en' => 'Laptops'], 'slug' => 'laptops', 'enabled' => true]);
        $this->feature = Feature::create([
            'name' => ['en' => 'Make'], 'code' => 'make', 'purpose' => 'brand', 'input_type' => 'select',
        ]);
        $this->apple = FeatureValue::create(['feature_id' => $this->feature->id, 'value' => ['en' => 'Apple'], 'slug' => 'apple']);
        $this->asus = FeatureValue::create(['feature_id' => $this->feature->id, 'value' => ['en' => 'Asus'], 'slug' => 'asus']);
    }

    public function test_the_page_answers_and_shows_only_what_it_selects(): void
    {
        $this->product('FP-1', 'Air', $this->apple);
        $this->product('FP-2', 'Zenbook', $this->asus);

        $page = $this->page([$this->feature->id => [$this->apple->id]], 'apple');

        $this->get('http://localhost'.$page->path())->assertOk()
            ->assertSee('Air')
            ->assertDontSee('Zenbook');
    }

    /** The line the table exists for — see the class docblock. */
    public function test_the_filtered_listing_canonicalises_onto_the_page(): void
    {
        $this->product('FP-3', 'Air', $this->apple);
        $page = $this->page([$this->feature->id => [$this->apple->id]], 'apple');

        $this->get('http://localhost/catalog/laptops?feature['.$this->feature->id.'][]='.$this->apple->id)
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.$page->url().'"', false);
    }

    /**
     * A visitor who has narrowed by something the address cannot express is
     * looking at FEWER products than the page holds, so pointing them at it
     * would be the one genuinely misleading answer.
     */
    public function test_a_price_narrowed_listing_does_not_claim_to_be_the_page(): void
    {
        $this->product('FP-4', 'Air', $this->apple);
        $page = $this->page([$this->feature->id => [$this->apple->id]], 'apple');

        // The page is still LINKED from the listing, as every published filter
        // page is — what must not happen is the head claiming to be it.
        $this->get('http://localhost/catalog/laptops?feature['.$this->feature->id.'][]='.$this->apple->id.'&price_max=1')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="http://localhost/catalog/laptops">', false)
            ->assertDontSee('<link rel="canonical" href="'.$page->url().'"', false);
    }

    /** Nothing to show is nothing to index — and it still answers. */
    public function test_an_empty_page_is_noindex_but_not_a_404(): void
    {
        $page = $this->page([$this->feature->id => [$this->asus->id]], 'asus');

        $this->get('http://localhost'.$page->path())->assertOk()
            ->assertSee('name="robots" content="noindex', false);
    }

    public function test_deleting_a_page_leaves_a_redirect_to_its_category(): void
    {
        $page = $this->page([$this->feature->id => [$this->apple->id]], 'apple');
        $path = $page->path();

        $this->actingAs($this->admin())
            ->delete('http://localhost/admin/catalog/filter-pages/'.$page->id)
            ->assertRedirect();

        $this->assertSame('/catalog/laptops', app(RedirectManager::class)->resolve($path));
        $this->get('http://localhost'.$path)->assertRedirect('/catalog/laptops');
    }

    public function test_the_sitemap_names_it(): void
    {
        $this->product('FP-5', 'Air', $this->apple);
        $page = $this->page([$this->feature->id => [$this->apple->id]], 'apple');

        $this->get('http://localhost/sitemap.xml')->assertOk()
            ->assertSee($page->url(), false);

        $page->update(['in_sitemap' => false]);

        $this->get('http://localhost/sitemap.xml')->assertOk()
            ->assertDontSee('/catalog/laptops/apple', false);
    }

    /** An SEO subject like any other, which is why the rules carry no template engine. */
    public function test_a_seo_template_writes_its_title(): void
    {
        $this->product('FP-6', 'Air', $this->apple);
        $page = $this->page([$this->feature->id => [$this->apple->id]], 'apple');

        Template::create([
            'subject' => 'filter_page', 'scope' => 'global', 'mode' => 'override', 'enabled' => true,
            'title' => ['en' => '{filter} {category} — {total_products} of them'],
        ]);
        app(Templates::class)->flush();

        $this->get('http://localhost'.$page->path())->assertOk()
            ->assertSee('Apple Laptops — 1 of them', false);
    }

    /**
     * The page's own words take the placeholders a template takes: the owner
     * wrote {min_price} into a page's own text and got it printed as typed
     * (2026-10-29). The title, the heading, the introduction, and the
     * description made of the introduction.
     */
    public function test_its_own_words_take_placeholders(): void
    {
        $this->product('FP-21', 'Air', $this->apple);
        $page = $this->page([$this->feature->id => [$this->apple->id]], 'apple');
        $page->update([
            'h1' => ['en' => '{filter} laptops: {total_products}'],
            'meta_title' => ['en' => 'Buy {filter} laptops'],
            'description' => ['en' => '<p>All {filter} laptops, {total_products} of them.</p>'],
        ]);

        $html = $this->get('http://localhost'.$page->path())->assertOk()->getContent();

        $this->assertStringContainsString('>Apple laptops: 1</h1>', $html);
        $this->assertMatchesRegularExpression('#<title>Buy Apple laptops[^<]*</title>#', $html);
        $this->assertStringContainsString('<meta name="description" content="All Apple laptops, 1 of them.">', $html);
        $this->assertMatchesRegularExpression('#class="torgash-intro-body[^"]*"><p>All Apple laptops, 1 of them\.</p>#', $html);
        $this->assertStringNotContainsString('{filter}', $html);
    }

    /**
     * ...including one written for its CATEGORY, which is the branch this kind
     * of page needed in Templates: a filter page hangs under exactly one
     * category, where a product hangs under several.
     */
    public function test_a_template_scoped_to_the_category_governs_it(): void
    {
        $this->product('FP-11', 'Air', $this->apple);
        $page = $this->page([$this->feature->id => [$this->apple->id]], 'apple');

        Template::create([
            'subject' => 'filter_page', 'scope' => 'category:'.$this->category->id,
            'mode' => 'override', 'enabled' => true,
            'title' => ['en' => 'Only in this category: {filter}'],
        ]);
        app(Templates::class)->flush();

        $this->get('http://localhost'.$page->path())->assertOk()
            ->assertSee('Only in this category: Apple', false);
    }

    public function test_a_rule_makes_a_page_per_value_and_a_second_run_adds_nothing(): void
    {
        $this->product('FP-7', 'Air', $this->apple);
        $this->product('FP-8', 'Zenbook', $this->asus);

        $rule = $this->rule();

        $first = (new Generator)->run($rule);

        $this->assertSame(2, $first['created']);
        $this->assertSame(['apple', 'asus'], FilterPage::orderBy('slug')->pluck('slug')->all());

        $second = (new Generator)->run($rule);

        $this->assertSame(0, $second['created']);
        $this->assertSame(2, FilterPage::count());
    }

    /**
     * A filter page on a branch counts and shows the branch.
     *
     * Both halves, because they are two implementations of one question: the
     * generator writes `products_count` and the page renders a grid, and a
     * count that contradicts the screen is worse than no count.
     */
    public function test_a_filter_page_on_a_parent_covers_its_subtree(): void
    {
        $child = Category::create([
            'name' => ['en' => 'Ultrabooks'], 'slug' => 'ultrabooks',
            'enabled' => true, 'parent_id' => $this->category->id,
        ]);
        app(CategoryTree::class)->flush();

        $deep = Product::create([
            'sku' => 'FP-13', 'name' => ['en' => 'Deep Air'], 'slug' => 'fp-13',
            'price' => 1000_00, 'stock' => 5, 'status' => 'active',
        ]);
        $deep->categories()->attach($child->id);
        ProductFeature::create([
            'product_id' => $deep->id,
            'feature_id' => $this->feature->id,
            'feature_value_id' => $this->apple->id,
        ]);

        (new Generator)->run($this->rule());

        $page = FilterPage::where('slug', 'apple')->firstOrFail();

        $this->assertSame(1, $page->products_count);
        $this->get('http://localhost'.$page->path())->assertOk()->assertSee('Deep Air');
    }

    /** A combination with too little in it gets no page at all. */
    public function test_a_thin_combination_is_not_published(): void
    {
        $this->product('FP-9', 'Air', $this->apple);

        (new Generator)->run($this->rule(['min_products' => 2]));

        $this->assertSame(0, FilterPage::count());
    }

    /**
     * ...but one that thins AFTER it was published keeps its address and only
     * leaves the sitemap. A 404 on a page search engines already hold is the
     * worst of the three answers and the hardest to notice.
     */
    public function test_a_page_that_thins_leaves_the_sitemap_and_not_existence(): void
    {
        $product = $this->product('FP-10', 'Air', $this->apple);
        $rule = $this->rule();

        (new Generator)->run($rule);

        $page = FilterPage::where('slug', 'apple')->firstOrFail();
        $this->assertTrue($page->in_sitemap);

        $product->update(['status' => 'hidden']);
        $counts = (new Generator)->run($rule);

        $this->assertSame(1, $counts['withdrawn']);
        $this->assertTrue(FilterPage::whereKey($page->id)->exists());
        $this->assertFalse($page->fresh()->in_sitemap);
    }

    /**
     * The four screens, rendered.
     *
     * A smoke test and deliberately shallow: what it is here to catch is a
     * blade that only fails when a rule exists, which is exactly the kind of
     * thing the rest of this file never opens.
     */
    public function test_the_admin_screens_render(): void
    {
        $page = $this->page([$this->feature->id => [$this->apple->id]], 'apple');
        $rule = $this->rule();
        $admin = $this->admin();

        foreach ([
            '/admin/catalog/filter-pages',
            '/admin/catalog/filter-pages/create',
            '/admin/catalog/filter-pages/'.$page->id.'/edit',
            '/admin/catalog/filter-rules',
            '/admin/catalog/filter-rules/create',
            '/admin/catalog/filter-rules/'.$rule->id.'/edit',
        ] as $url) {
            $this->actingAs($admin)->get('http://localhost'.$url)->assertOk();
        }
    }

    /**
     * The list of links on a category costs the same whatever its length.
     *
     * Each link asks its page for its address and its words, and both are a
     * query if nobody primed them — the same N+1 shape `withCardData()` exists
     * for, and just as invisible, because the page renders perfectly either way.
     */
    public function test_the_links_on_a_category_do_not_cost_a_query_each(): void
    {
        $this->product('FP-12', 'Air', $this->apple);
        $this->page([$this->feature->id => [$this->apple->id]], 'apple');

        // Unmeasured first: the per-request memos are filled once per process.
        $this->get('http://localhost/catalog/laptops')->assertOk();
        $one = $this->queriesForCategory();

        $this->page([$this->feature->id => [$this->asus->id]], 'asus');
        $this->page([$this->feature->id => [$this->apple->id, $this->asus->id]], 'either');

        $this->assertSame($one, $this->queriesForCategory(), 'the category loads something per filter page');
    }

    protected function queriesForCategory(): int
    {
        app(FilterPages::class)->flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get('http://localhost/catalog/laptops')->assertOk();

        $count = count(DB::getRawQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** Two addresses for one set of products is the duplication this removes. */
    public function test_the_same_combination_cannot_be_published_twice(): void
    {
        $this->page([$this->feature->id => [$this->apple->id]], 'apple');

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/catalog/filter-pages', [
                'category_id' => $this->category->id,
                'slug' => 'apple-again',
                'values' => [$this->feature->id => [$this->apple->id]],
            ])
            ->assertSessionHasErrors('values');

        $this->assertSame(1, FilterPage::count());
    }

    /** Order and typing must not make two different sets look different. */
    public function test_the_fingerprint_ignores_the_order_the_values_were_written_in(): void
    {
        $this->assertSame(
            FilterPage::fingerprintFor(1, [3 => [9, 4], 2 => ['7']]),
            FilterPage::fingerprintFor(1, [2 => [7], 3 => ['4', 9]]),
        );
    }

    protected function page(array $values, string $slug): FilterPage
    {
        return FilterPage::create([
            'category_id' => $this->category->id,
            'slug' => $slug,
            'values' => FilterPage::canonicalValues($values),
            'fingerprint' => FilterPage::fingerprintFor($this->category->id, $values),
            'enabled' => true,
            'in_sitemap' => true,
        ]);
    }

    protected function rule(array $extra = []): FilterRule
    {
        // `$extra` FIRST: with PHP's + the left-hand keys win, so defaults
        // written on the left would silently ignore what a test asked for.
        return FilterRule::create($extra + [
            'name' => 'A page per make',
            'feature_id' => $this->feature->id,
            'category_ids' => [$this->category->id],
            'with_children' => false,
            'slug_template' => '{filter}',
            'min_products' => 1,
            'in_sitemap' => true,
            'enabled' => true,
        ]);
    }

    protected function product(string $sku, string $name, FeatureValue $value): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => 1000_00, 'stock' => 5, 'status' => 'active',
        ]);

        $product->categories()->attach($this->category->id);
        ProductFeature::create([
            'product_id' => $product->id,
            'feature_id' => $this->feature->id,
            'feature_value_id' => $value->id,
        ]);

        return $product;
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
