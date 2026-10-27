<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Filters\FilterQuery;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;

/**
 * The sidebar filters, and the row that says what is in force.
 *
 * Reported from the shop: ticking one box gave
 * `?price_min=&price_max=&feature[8][]=22&price_min=&price_max=` — and
 * unticking it again changed nothing at all. One cause for both. The pill row
 * above the grid was a SECOND COPY of the whole filter panel, with the same
 * input names, inside the same form: every value was submitted twice, and
 * clearing a box in one copy left the other one ticked and still submitting.
 *
 * So the row above the grid is now LINKS — what is filtered, and the address
 * that drops it — and the sidebar is the only place a filter is chosen. A chip
 * cannot be half of a form's state, which is the property worth pinning.
 */
class CatalogFiltersTest extends TestCase
{
    protected Category $category;

    protected Feature $feature;

    protected FeatureValue $steel;

    protected FeatureValue $wood;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => ['en' => 'Cookware'], 'slug' => 'filters-cookware', 'enabled' => true]);
        $this->feature = Feature::create([
            'name' => ['en' => 'Material'], 'code' => 'filters_material', 'purpose' => 'filter', 'input_type' => 'select',
        ]);
        $this->steel = FeatureValue::create(['feature_id' => $this->feature->id, 'value' => ['en' => 'Stainless steel']]);
        $this->wood = FeatureValue::create(['feature_id' => $this->feature->id, 'value' => ['en' => 'Wood']]);
    }

    /** The bug itself: one control per filter, or unticking cannot work. */
    public function test_each_filter_control_appears_exactly_once(): void
    {
        $this->product('FLT-1', 'Steel pan', $this->steel);

        $body = $this->get('http://localhost/catalog')->assertOk()->getContent();

        $this->assertSame(1, substr_count($body, 'name="price_min"'), 'the price box is on the page more than once');
        $this->assertSame(1, substr_count($body, 'name="price_max"'));
        $this->assertSame(1, substr_count($body, 'name="in_stock"'));
        $this->assertSame(
            1,
            substr_count($body, 'name="feature['.$this->feature->id.'][]" value="'.$this->steel->id.'"'),
            'a characteristic value has two checkboxes, so clearing one submits the other',
        );
    }

    public function test_a_feature_filter_narrows_the_grid(): void
    {
        $this->product('FLT-2', 'Steel pan', $this->steel);
        $this->product('FLT-3', 'Wooden spoon', $this->wood);

        $this->get('http://localhost/catalog?feature['.$this->feature->id.'][]='.$this->steel->id)
            ->assertOk()
            ->assertSee('Steel pan')
            ->assertDontSee('Wooden spoon');
    }

    /** What is in force is said in words, with the way out of each one. */
    public function test_an_active_filter_is_named_and_can_be_dropped(): void
    {
        $this->product('FLT-4', 'Steel pan', $this->steel);

        $this->get('http://localhost/catalog?feature['.$this->feature->id.'][]='.$this->steel->id)
            ->assertOk()
            ->assertSee('Material: Stainless steel')
            // The whole filter gone: the bare address, not `?feature[…]=`.
            ->assertSee('href="http://localhost/catalog"', false);
    }

    /** Dropping one value keeps the others — and keeps the form's own spelling. */
    public function test_dropping_one_value_keeps_the_rest(): void
    {
        $this->product('FLT-5', 'Steel pan', $this->steel);
        $this->product('FLT-6', 'Wooden spoon', $this->wood);

        $url = 'http://localhost/catalog?feature['.$this->feature->id.'][]='.$this->steel->id
            .'&feature['.$this->feature->id.'][]='.$this->wood->id;

        $body = $this->get($url)->assertOk()->getContent();

        // Spoken, because both halves have codes — every row is given one now
        // (Support\AssignsCode), so the id spelling is left for a
        // characteristic whose code would collide with a word the catalogue
        // owns. What is pinned here is that ONE value is dropped and the other
        // is kept, whichever spelling the page is written in.
        $this->assertStringContainsString(
            'href="http://localhost/catalog?'.$this->feature->code.'='.strtolower($this->wood->code).'"',
            $body,
        );

        // Never http_build_query's numbered `feature[N][0]=`, which would be a
        // third spelling of an address that already has two.
        $this->assertStringNotContainsString('%5B0%5D=', $body);
    }

    /** A price range is one chip, and dropping it leaves the rest alone. */
    public function test_the_price_range_is_one_chip(): void
    {
        $this->product('FLT-7', 'Steel pan', $this->steel);

        $body = $this->get('http://localhost/catalog?price_min=1&feature['.$this->feature->id.'][]='.$this->steel->id)
            ->assertOk()->getContent();

        $this->assertStringContainsString('Price: 1 —', $body);
        $this->assertStringContainsString(
            'href="http://localhost/catalog?'.$this->feature->code.'='.strtolower($this->steel->code).'"',
            $body,
        );
    }

    /**
     * A filter page's own values are the ADDRESS, not a chip: the way off them
     * is the category, and a cross that led nowhere would be a lie.
     */
    public function test_a_filter_pages_own_values_are_not_offered_as_chips(): void
    {
        $this->product('FLT-8', 'Steel pan', $this->steel);

        $values = [$this->feature->id => [$this->steel->id]];
        $page = FilterPage::create([
            'category_id' => $this->category->id,
            'slug' => 'steel',
            'values' => FilterPage::canonicalValues($values),
            'fingerprint' => FilterPage::fingerprintFor($this->category->id, $values),
            'enabled' => true,
            'in_sitemap' => true,
        ]);

        $this->get('http://localhost'.$page->path())->assertOk()
            ->assertDontSee('Filtered by');
    }

    /**
     * The address a person can read — the shop's own complaint about
     * `?feature%5B8%5D%5B%5D=22`.
     */
    public function test_a_coded_characteristic_is_filtered_by_its_name(): void
    {
        [$brand, $acme, $nord] = $this->codedFeature();
        $this->product('FLT-10', 'Acme pan', $this->steel, $acme);
        $this->product('FLT-11', 'Nord pan', $this->steel, $nord);

        $this->get('http://localhost/catalog?brand=acme')->assertOk()
            ->assertSee('Acme pan')
            ->assertDontSee('Nord pan');
    }

    /** A comma is "any of these" — a checkbox group cannot say it any other way. */
    public function test_a_comma_means_any_of_these(): void
    {
        [$brand, $acme, $nord] = $this->codedFeature();
        $this->product('FLT-12', 'Acme pan', $this->steel, $acme);
        $this->product('FLT-13', 'Nord pan', $this->steel, $nord);

        $this->get('http://localhost/catalog?brand=acme,nord')->assertOk()
            ->assertSee('Acme pan')
            ->assertSee('Nord pan');
    }

    /** The old spelling is read for ever, and the two mix in one address. */
    public function test_both_spellings_are_read_and_can_be_mixed(): void
    {
        [$brand, $acme] = $this->codedFeature();
        $this->product('FLT-14', 'Acme steel', $this->steel, $acme);
        $this->product('FLT-15', 'Acme wood', $this->wood, $acme);

        $this->get('http://localhost/catalog?brand=acme')->assertOk()->assertSee('Acme steel');

        // The characteristic by name, the one with no value codes by id.
        $this->get('http://localhost/catalog?brand=acme&feature['.$this->feature->id.'][]='.$this->steel->id)
            ->assertOk()
            ->assertSee('Acme steel')
            ->assertDontSee('Acme wood');
    }

    /** Once both halves have codes, what the page emits is the readable form. */
    public function test_the_page_writes_the_readable_form(): void
    {
        [$brand, $acme, $nord] = $this->codedFeature();
        $this->product('FLT-16', 'Acme pan', $this->steel, $acme);
        $this->product('FLT-17', 'Nord pan', $this->steel, $nord);

        $body = $this->get('http://localhost/catalog?brand=acme,nord')->assertOk()->getContent();

        // The chip that drops one leaves the other, spoken.
        $this->assertStringContainsString('href="http://localhost/catalog?brand=nord"', $body);
        $this->assertStringNotContainsString('feature%5B'.$brand->id.'%5D', $body);

        // ...and the box carries the name the form will build the address from.
        $this->assertStringContainsString('data-param="brand" data-value="acme"', $body);
    }

    /**
     * The disagreement that mattered: an unknown value ID matches nothing, so
     * an unknown CODE must too — otherwise one spelling of an address shows the
     * whole catalogue where the other shows an empty grid.
     */
    public function test_a_code_nobody_knows_matches_nothing_and_says_so(): void
    {
        [$brand, $acme] = $this->codedFeature();
        $this->product('FLT-18', 'Acme pan', $this->steel, $acme);

        $this->get('http://localhost/catalog?brand=acme')->assertOk()->assertSee('Acme pan');

        $this->get('http://localhost/catalog?brand=gone')->assertOk()
            ->assertDontSee('Acme pan')
            // ...and it is named, or the empty page explains nothing and there
            // is no way back but editing the address.
            ->assertSee('Make: gone')
            ->assertSee('href="http://localhost/catalog"', false);
    }

    /** A characteristic coded like something the catalogue owns keeps its ids. */
    public function test_a_characteristic_may_not_take_a_parameter_the_catalogue_owns(): void
    {
        $reserved = Feature::create([
            'name' => ['en' => 'Page'], 'code' => 'PAGE', 'purpose' => 'filter', 'input_type' => 'select',
        ]);
        $value = FeatureValue::create(['feature_id' => $reserved->id, 'value' => ['en' => 'Big'], 'code' => 'BIG']);
        app(FilterQuery::class)->flush();

        $product = Product::create([
            'sku' => 'FLT-19', 'name' => ['en' => 'Paged thing'], 'slug' => 'flt-19',
            'price' => 100_00, 'stock' => 4, 'status' => 'active',
        ]);
        $product->categories()->attach($this->category->id);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $reserved->id, 'feature_value_id' => $value->id]);

        $body = $this->get('http://localhost/catalog')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-param="page"', $body);

        // `?page=big` must still be the pagination's word, so it filters nothing.
        $this->get('http://localhost/catalog?feature['.$reserved->id.'][]='.$value->id)
            ->assertOk()->assertSee('Paged thing');
    }

    /**
     * The shop is told what its own address will say.
     *
     * Without this the whole feature is invisible: a code is a field nobody has
     * a reason to fill until they learn it is what makes the address readable.
     */
    public function test_the_feature_screen_shows_the_address_it_produces(): void
    {
        [$brand] = $this->codedFeature();

        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)
            ->get('http://localhost/admin/catalog/features/'.$brand->id.'/edit')
            ->assertOk()
            ->assertSee('/catalog?brand=acme');
    }

    /**
     * The shop's report: "Оперативная память" on the Clothing page.
     *
     * A characteristic attached to no category applies everywhere by design —
     * that is how Brand and Colour work — and nothing asked whether there was
     * anything here to filter, so the panel offered a choice of memory for a
     * jumper and either answer led to an empty grid.
     */
    public function test_a_characteristic_nothing_here_carries_is_not_offered(): void
    {
        $ram = Feature::create([
            'name' => ['en' => 'RAM'], 'code' => 'RAM_GB', 'purpose' => 'filter', 'input_type' => 'select',
        ]);
        FeatureValue::create(['feature_id' => $ram->id, 'value' => ['en' => '16 GB'], 'code' => '16-GB']);

        $this->product('FLT-20', 'Steel pan', $this->steel);

        $this->get('http://localhost/catalog/'.$this->category->slug)->assertOk()
            ->assertSee('Material')
            ->assertDontSee('16 GB');
    }

    /** Same rule one level down: a value nothing here has is not a choice. */
    public function test_a_value_nothing_here_carries_is_not_offered(): void
    {
        $this->product('FLT-21', 'Steel pan', $this->steel);

        $this->get('http://localhost/catalog/'.$this->category->slug)->assertOk()
            ->assertSee('Stainless steel')
            ->assertDontSee('Wood');
    }

    /**
     * ...but measured against the CATEGORY, never against what is already
     * ticked. Narrowing the options by the current selection is the tempting
     * version and it is a trap: pick one material and it becomes the only one
     * on offer, with no way to widen again but editing the address.
     */
    public function test_ticking_one_value_does_not_hide_the_others(): void
    {
        $this->product('FLT-22', 'Steel pan', $this->steel);
        $this->product('FLT-23', 'Wooden spoon', $this->wood);

        $this->get('http://localhost/catalog/'.$this->category->slug.'?feature['.$this->feature->id.'][]='.$this->steel->id)
            ->assertOk()
            ->assertSee('Stainless steel')
            ->assertSee('Wood');
    }

    /** The shop's own statement still wins: attached here means not over there. */
    public function test_a_characteristic_attached_to_another_branch_is_not_offered(): void
    {
        $other = Category::create(['name' => ['en' => 'Tools'], 'slug' => 'filters-tools', 'enabled' => true]);
        $this->feature->categories()->attach($other->id);

        $this->product('FLT-24', 'Steel pan', $this->steel);

        $this->get('http://localhost/catalog/'.$this->category->slug)->assertOk()
            ->assertDontSee('Stainless steel');
    }

    /** @return array{0: Feature, 1: FeatureValue, 2: FeatureValue} */
    protected function codedFeature(): array
    {
        $feature = Feature::create([
            'name' => ['en' => 'Make'], 'code' => 'BRAND', 'purpose' => 'brand', 'input_type' => 'select',
        ]);
        $acme = FeatureValue::create(['feature_id' => $feature->id, 'value' => ['en' => 'Acme'], 'code' => 'ACME']);
        $nord = FeatureValue::create(['feature_id' => $feature->id, 'value' => ['en' => 'Nord'], 'code' => 'NORD']);

        // The maps are memoised per request, and this one was built before the
        // rows existed.
        app(FilterQuery::class)->flush();

        return [$feature, $acme, $nord];
    }

    protected function product(string $sku, string $name, FeatureValue $value, ?FeatureValue $extra = null): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => 100_00, 'stock' => 4, 'status' => 'active',
        ]);

        $product->categories()->attach($this->category->id);
        ProductFeature::create([
            'product_id' => $product->id,
            'feature_id' => $this->feature->id,
            'feature_value_id' => $value->id,
        ]);

        if ($extra) {
            ProductFeature::create([
                'product_id' => $product->id,
                'feature_id' => $extra->feature_id,
                'feature_value_id' => $extra->id,
            ]);
        }

        return $product;
    }
}
