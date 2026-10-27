<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
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
use Torgash\Locations\Models\Place;
use Torgash\Seo\Models\Template;
use Torgash\Seo\Templates;

/**
 * A city page — PLAN §30.9, the last phase of the SEO arc.
 *
 * The same table and the same route as a filter page, and the properties worth
 * pinning are the ones where the two must NOT behave alike:
 *
 *  - it holds the same products as its category (a town is words, not a filter);
 *  - no filtered listing ever canonicalises to it, because no query string
 *    means "in Kyiv" — pointing one at it would claim two different sets of
 *    products are one page;
 *  - it is its own kind of page as far as the templates are concerned, so
 *    "{category} в {location:6}" can be written for city pages without
 *    reaching every filter page in the shop;
 *  - the town declines, and it declines in the language the page is being
 *    read in.
 */
class GeoPageTest extends TestCase
{
    protected Category $category;

    protected Feature $feature;

    protected FeatureValue $apple;

    protected Place $kyiv;

    protected Place $lviv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => ['en' => 'Laptops', 'ru' => 'Ноутбуки'], 'slug' => 'geo-laptops', 'enabled' => true,
        ]);
        $this->feature = Feature::create([
            'name' => ['en' => 'Make'], 'code' => 'geo_make', 'purpose' => 'brand', 'input_type' => 'select',
        ]);
        $this->apple = FeatureValue::create([
            'feature_id' => $this->feature->id, 'value' => ['en' => 'Apple'], 'slug' => 'geo-apple', 'code' => 'apple',
        ]);

        $this->kyiv = $this->place(['en' => 'Kyiv', 'ru' => 'Киев', 'uk' => 'Київ']);
        $this->lviv = $this->place(['en' => 'Lviv', 'ru' => 'Львов', 'uk' => 'Львів']);
    }

    public function test_a_city_page_answers_and_shows_the_whole_category(): void
    {
        $this->product('GEO-1', 'Air', $this->apple);
        $this->product('GEO-2', 'Zenbook');

        $page = $this->geoPage($this->kyiv, 'kyiv');

        $this->get('http://localhost'.$page->path())->assertOk()
            ->assertSee('Air')
            // A town does not narrow anything: that is the whole difference
            // between this and a filter page.
            ->assertSee('Zenbook');
    }

    /** ...and it can be both at once: "Apple laptops in Kyiv". */
    public function test_a_page_can_carry_values_and_a_town(): void
    {
        $this->product('GEO-3', 'Air', $this->apple);
        $this->product('GEO-4', 'Zenbook');

        $page = $this->geoPage($this->kyiv, 'apple-kyiv', [$this->feature->id => [$this->apple->id]]);

        $this->get('http://localhost'.$page->path())->assertOk()
            ->assertSee('Air')
            ->assertDontSee('Zenbook');
    }

    /**
     * The rule that keeps the two kinds of page apart in the one table: the
     * same values in the same category are a DIFFERENT page in another town.
     */
    public function test_the_town_is_part_of_what_makes_a_page_unique(): void
    {
        $plain = FilterPage::fingerprintFor($this->category->id, []);

        $this->assertNotSame($plain, FilterPage::fingerprintFor($this->category->id, [], $this->kyiv->id));
        $this->assertNotSame(
            FilterPage::fingerprintFor($this->category->id, [], $this->kyiv->id),
            FilterPage::fingerprintFor($this->category->id, [], $this->lviv->id),
        );

        // ...and a page with no town fingerprints exactly as it did before city
        // pages existed, which is what lets the UNIQUE column stay as it is.
        $this->assertSame($this->category->id.'|', $plain);
    }

    /**
     * A filtered listing must NEVER point its canonical at a city page: there
     * is no query string that means "in Kyiv", so the two are not two
     * addresses for one set of products.
     */
    public function test_a_filtered_listing_does_not_canonicalise_to_a_city_page(): void
    {
        $this->product('GEO-5', 'Air', $this->apple);

        // The same values as the listing below — but in a town.
        $this->geoPage($this->kyiv, 'apple-kyiv', [$this->feature->id => [$this->apple->id]]);

        // The LINK to it is right and deliberate — a published address nothing
        // links to is one nothing crawls, which is what the strip of pages
        // above the grid exists for. What must not happen is the canonical.
        $body = $this->get('http://localhost/catalog/'.$this->category->slug.'?apple=apple')->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'rel="canonical" href="http://localhost/catalog/'.$this->category->slug.'/apple-kyiv"',
            $body,
        );
        $this->assertStringContainsString(
            'rel="canonical" href="http://localhost/catalog/'.$this->category->slug.'"',
            $body,
        );
    }

    /** Its own kind of page, so its own templates. */
    public function test_a_template_written_for_city_pages_governs_it(): void
    {
        $this->product('GEO-6', 'Air', $this->apple);

        $page = $this->geoPage($this->kyiv, 'kyiv');
        $filter = FilterPage::create([
            'category_id' => $this->category->id,
            'slug' => 'apple',
            'values' => FilterPage::canonicalValues([$this->feature->id => [$this->apple->id]]),
            'fingerprint' => FilterPage::fingerprintFor($this->category->id, [$this->feature->id => [$this->apple->id]]),
            'enabled' => true,
            'in_sitemap' => true,
        ]);

        Template::create([
            'subject' => 'geo_page', 'scope' => 'global', 'mode' => 'override', 'enabled' => true,
            'title' => ['en' => '{category} in {location:1}'],
        ]);
        app(Templates::class)->flush();

        $this->get('http://localhost'.$page->path())->assertOk()
            ->assertSee('Laptops in Kyiv', false);

        // ...and the filter page beside it is untouched by that template.
        $this->get('http://localhost'.$filter->path())->assertOk()
            ->assertDontSee('Laptops in Kyiv', false);
    }

    /**
     * The town declines, and in the language the page is being READ in — a
     * Russian title with a Ukrainian genitive in it is the failure this is
     * here to catch.
     */
    public function test_the_town_is_declined_in_the_language_of_the_page(): void
    {
        $this->product('GEO-7', 'Air', $this->apple);

        $page = $this->geoPage($this->kyiv, 'kyiv');

        Template::create([
            'subject' => 'geo_page', 'scope' => 'global', 'mode' => 'override', 'enabled' => true,
            'title' => ['en' => 'Laptops in {location:6}', 'ru' => 'Ноутбуки в {location:6}'],
        ]);
        app(Templates::class)->flush();

        $this->get('http://localhost'.$page->path())->assertOk()->assertSee('Laptops in Kyiv', false);
        $this->get('http://localhost/ru'.$page->path())->assertOk()->assertSee('Ноутбуки в Киеве', false);
    }

    /** What the shop typed beats what the rules derive — for that one town. */
    public function test_a_hand_written_form_wins(): void
    {
        $this->kyiv->update(['forms' => ['ru' => [6 => 'Киеве-на-Днепре']]]);
        $this->product('GEO-8', 'Air', $this->apple);

        $page = $this->geoPage($this->kyiv, 'kyiv');

        Template::create([
            'subject' => 'geo_page', 'scope' => 'global', 'mode' => 'override', 'enabled' => true,
            'title' => ['ru' => 'Ноутбуки в {location:6}'],
        ]);
        app(Templates::class)->flush();

        $this->get('http://localhost/ru'.$page->path())->assertOk()->assertSee('Ноутбуки в Киеве-на-Днепре', false);
    }

    /** A rule whose axis is towns: one page per town, and a second run adds nothing. */
    public function test_a_rule_makes_a_page_per_town(): void
    {
        $this->product('GEO-9', 'Air', $this->apple);

        $rule = FilterRule::create([
            'name' => 'A page per town',
            'place_ids' => [$this->kyiv->id, $this->lviv->id],
            'category_ids' => [$this->category->id],
            'with_children' => false,
            'slug_template' => '{location:1}',
            'min_products' => 1,
            'in_sitemap' => true,
            'enabled' => true,
        ]);

        $this->assertSame(FilterRule::AXIS_PLACE, $rule->axis());

        $first = (new Generator)->run($rule);
        $this->assertSame(2, $first['created']);

        $pages = FilterPage::whereNotNull('place_id')->orderBy('slug')->get();
        $this->assertSame(['kyiv', 'lviv'], $pages->pluck('slug')->all());
        $this->assertSame([1, 1], $pages->pluck('products_count')->all());

        $second = (new Generator)->run($rule);
        $this->assertSame(0, $second['created']);
    }

    /**
     * The address is written in the BASE language whatever the run's own locale
     * is: a nightly job under `ru` would otherwise publish a different address
     * from the same rule run by hand, and an address may not move.
     */
    public function test_the_address_is_written_in_the_base_language(): void
    {
        $this->product('GEO-10', 'Air', $this->apple);

        $rule = FilterRule::create([
            'name' => 'A page per town',
            'place_ids' => [$this->kyiv->id],
            'category_ids' => [$this->category->id],
            'with_children' => false,
            'min_products' => 1,
            'in_sitemap' => true,
            'enabled' => true,
        ]);

        app()->setLocale('ru');

        try {
            (new Generator)->run($rule);
        } finally {
            app()->setLocale('en');
        }

        $this->assertSame('kyiv', FilterPage::whereNotNull('place_id')->value('slug'));
    }

    /** A city page is in the sitemap like any other published address. */
    public function test_a_city_page_is_in_the_sitemap(): void
    {
        $this->product('GEO-11', 'Air', $this->apple);
        $page = $this->geoPage($this->kyiv, 'kyiv');

        $this->get('http://localhost/sitemap.xml')->assertOk()
            ->assertSee('http://localhost'.$page->path(), false);
    }

    /**
     * A visitor who arrived on the Kyiv page has said where they are — the
     * delivery estimate in the cart should not still be asking.
     */
    public function test_arriving_on_a_city_page_answers_the_delivery_question(): void
    {
        $this->product('GEO-12', 'Air', $this->apple);
        $page = $this->geoPage($this->kyiv, 'kyiv');

        $this->get('http://localhost'.$page->path())->assertOk();

        $this->assertSame($this->kyiv->id, (int) hook_filter('storefront.visitor_place', null));
    }

    /** The admin refuses a page that stands for nothing, and accepts a town alone. */
    public function test_the_form_accepts_a_town_with_no_values(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)->post('http://localhost/admin/catalog/filter-pages', [
            'category_id' => $this->category->id,
            'place_id' => $this->kyiv->id,
            'slug' => 'kyiv',
            'enabled' => '1',
            'in_sitemap' => '1',
        ])->assertRedirect();

        $this->assertSame(1, FilterPage::whereNotNull('place_id')->count());

        $this->actingAs($user)->post('http://localhost/admin/catalog/filter-pages', [
            'category_id' => $this->category->id,
            'slug' => 'nothing',
        ])->assertSessionHasErrors('values');
    }

    /**
     * The two screens this phase added a field to, rendered.
     *
     * A smoke test with a reason: both are Alpine markup inside a Blade
     * template, where a mistake is a 500 on a page no test would otherwise
     * open — and the towns picker is included from ANOTHER module's partial,
     * so it also pins that the soft dependency resolves.
     */
    public function test_the_screens_that_gained_a_field_render(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)->get('http://localhost/admin/catalog/filter-rules/create')
            ->assertOk()
            ->assertSee('placePicker', false)
            ->assertSee('name="axis"', false);

        $page = $this->geoPage($this->kyiv, 'kyiv');

        $this->actingAs($user)->get('http://localhost/admin/catalog/filter-pages/'.$page->id.'/edit')
            ->assertOk()
            ->assertSee('name="place_id"', false);

        // ...and the town's own screen, where the cases are typed.
        $this->actingAs($user)->get('http://localhost/admin/locations/'.$this->kyiv->id.'/edit')
            ->assertOk()
            ->assertSee('name="forms[ru][6]"', false)
            // The derived form is the placeholder, which is what makes the
            // field self-explanatory: the shop sees the guess and corrects it.
            ->assertSee('placeholder="Киеве"', false);
    }

    protected function place(array $names): Place
    {
        return Place::create([
            'country' => 'UA',
            'name' => $names,
            'type' => 'region_center',
            'enabled' => true,
            'source' => 'manual',
            'population' => 1000000,
        ]);
    }

    protected function geoPage(Place $place, string $slug, array $values = []): FilterPage
    {
        $page = FilterPage::create([
            'category_id' => $this->category->id,
            'place_id' => $place->id,
            'slug' => $slug,
            'values' => FilterPage::canonicalValues($values),
            'fingerprint' => FilterPage::fingerprintFor($this->category->id, $values, $place->id),
            'enabled' => true,
            'in_sitemap' => true,
        ]);

        app(FilterPages::class)->flush();

        return $page;
    }

    protected function product(string $sku, string $name, ?FeatureValue $value = null): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => 1000_00, 'stock' => 5, 'status' => 'active',
        ]);

        $product->categories()->attach($this->category->id);

        if ($value) {
            ProductFeature::create([
                'product_id' => $product->id,
                'feature_id' => $value->feature_id,
                'feature_value_id' => $value->id,
            ]);
        }

        return $product;
    }
}
