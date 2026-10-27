<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Models\ProductTab;

/**
 * Tabs an admin writes onto the product page — PLAN §30.10.
 *
 * The properties worth pinning are the ones where a tab differs from a block:
 * it is one of a STRIP, so its label has to exist before any content renders;
 * it may belong to one product; and where it is shown otherwise is core's
 * display rules, with their fail-closed rule intact.
 */
class ProductTabsTest extends TestCase
{
    protected Category $category;

    protected Product $laptop;

    protected Product $kettle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => ['en' => 'Laptops'], 'slug' => 'tab-laptops', 'enabled' => true,
        ]);

        $this->laptop = $this->product('TAB-1', 'Air');
        $this->laptop->categories()->attach($this->category->id);

        $this->kettle = $this->product('TAB-2', 'Kettle');
    }

    public function test_a_written_tab_is_on_every_product(): void
    {
        ProductTab::create([
            'title' => ['en' => 'Delivery'],
            'content' => ['en' => '<p>Two days by courier.</p>'],
            'enabled' => true, 'sort' => 50, 'rules_match' => 'all',
        ]);

        $this->get('http://localhost/product/tab-1')->assertOk()
            ->assertSee('Delivery')->assertSee('Two days by courier.', false);

        $this->get('http://localhost/product/tab-2')->assertOk()->assertSee('Delivery');
    }

    public function test_a_tab_written_for_one_product_stays_there(): void
    {
        ProductTab::create([
            'product_id' => $this->laptop->id,
            'title' => ['en' => 'Care instructions'],
            'content' => ['en' => '<p>Keep it dry.</p>'],
            'enabled' => true, 'sort' => 50, 'rules_match' => 'all',
        ]);

        $this->get('http://localhost/product/tab-1')->assertOk()->assertSee('Care instructions');
        $this->get('http://localhost/product/tab-2')->assertOk()->assertDontSee('Care instructions');
    }

    /** Core's rules, asked about this product — the same engine a block uses. */
    public function test_display_rules_decide_where_a_shop_wide_tab_shows(): void
    {
        ProductTab::create([
            'title' => ['en' => 'Laptop warranty'],
            'content' => ['en' => '<p>Three years.</p>'],
            'enabled' => true, 'sort' => 50, 'rules_match' => 'all',
            'rules' => [['kind' => 'product_category', 'operator' => 'in', 'value' => [$this->category->id]]],
        ]);

        $this->get('http://localhost/product/tab-1')->assertOk()->assertSee('Laptop warranty');
        $this->get('http://localhost/product/tab-2')->assertOk()->assertDontSee('Laptop warranty');
    }

    public function test_a_disabled_or_empty_tab_is_not_drawn(): void
    {
        ProductTab::create([
            'title' => ['en' => 'Switched off'], 'content' => ['en' => '<p>x</p>'],
            'enabled' => false, 'sort' => 50, 'rules_match' => 'all',
        ]);

        // A label that opens onto nothing reads as a broken page, so a tab with
        // no body is not a tab.
        ProductTab::create([
            'title' => ['en' => 'Nothing written yet'], 'content' => null,
            'enabled' => true, 'sort' => 50, 'rules_match' => 'all',
        ]);

        $this->get('http://localhost/product/tab-1')->assertOk()
            ->assertDontSee('Switched off')
            ->assertDontSee('Nothing written yet');
    }

    /** The title speaks about the product it is on — `[feature_12]` elsewhere, by code here. */
    public function test_placeholders_work_in_the_title_and_the_body(): void
    {
        $feature = Feature::create([
            'name' => ['en' => 'Memory'], 'code' => 'tab_ram', 'purpose' => 'info', 'input_type' => 'text',
        ]);
        ProductFeature::create([
            'product_id' => $this->laptop->id, 'feature_id' => $feature->id, 'value_text' => '16 GB',
        ]);

        ProductTab::create([
            'title' => ['en' => '{feature_name:tab_ram}: {feature:tab_ram}'],
            'content' => ['en' => '<p>{product} ships with {feature:tab_ram}.</p>'],
            'enabled' => true, 'sort' => 50, 'rules_match' => 'all',
            'rules' => [['kind' => 'product_category', 'operator' => 'in', 'value' => [$this->category->id]]],
        ]);

        $this->get('http://localhost/product/tab-1')->assertOk()
            ->assertSee('Memory: 16 GB')
            ->assertSee('Air ships with 16 GB.', false);
    }

    /** Position 5 puts it in front of the built-in Description (10). */
    public function test_position_interleaves_with_the_built_in_tabs(): void
    {
        $this->laptop->update(['description' => ['en' => 'A thin laptop.']]);

        ProductTab::create([
            'title' => ['en' => 'Read this first'], 'content' => ['en' => '<p>Do.</p>'],
            'enabled' => true, 'sort' => 5, 'rules_match' => 'all',
        ]);

        $body = $this->get('http://localhost/product/tab-1')->assertOk()->getContent();

        // The labels in the strip, not the panels: the description's own text
        // is also in the page's meta description, which is the first thing in
        // the document and would make any comparison against it meaningless.
        $this->assertLessThan(
            strpos($body, '>Description</a>'),
            strpos($body, '>Read this first</a>'),
            'a tab at position 5 must stand in front of the description at 10',
        );
    }

    /**
     * The second layout: everything open, each section under a heading with
     * room for the product in it. The same list drawn differently — which is
     * what keeps the two layouts from becoming two different pages.
     */
    public function test_sections_mode_prints_headings_and_opens_everything(): void
    {
        $this->laptop->update(['description' => ['en' => 'A thin laptop.']]);

        ProductTab::create([
            'title' => ['en' => 'Reviews'],
            'heading' => ['en' => 'Reviews of {product}'],
            'content' => ['en' => '<p>None yet.</p>'],
            'enabled' => true, 'sort' => 50, 'rules_match' => 'all',
        ]);

        // As tabs, the strip carries the short word and the heading is unused.
        $this->get('http://localhost/product/tab-1')->assertOk()
            ->assertSee('Reviews')
            ->assertDontSee('Reviews of Air');

        $this->asSections();

        $body = $this->get('http://localhost/product/tab-1')->assertOk()
            ->assertSee('Reviews of Air')
            ->getContent();

        // An anchor per section and no panel switching: the jump row points at
        // content that is already on the page.
        $this->assertStringContainsString('href="#description"', $body);
        $this->assertStringNotContainsString('x-show="tab ===', $body);
    }

    /** A row may name one of the page's own sections and give it better words. */
    public function test_a_row_renames_a_built_in_section(): void
    {
        $this->laptop->update(['description' => ['en' => 'A thin laptop.']]);

        ProductTab::create([
            'section' => 'description',
            'title' => ['en' => 'All about it'],
            'heading' => ['en' => 'All about {product}'],
            'enabled' => true, 'sort' => 10, 'rules_match' => 'all',
        ]);

        $this->get('http://localhost/product/tab-1')->assertOk()
            ->assertSee('All about it')
            ->assertDontSee('>Description</a>', false)
            // ...and the section still holds the page's own content.
            ->assertSee('A thin laptop.');

        $this->asSections();

        $this->get('http://localhost/product/tab-1')->assertOk()->assertSee('All about Air');
    }

    /** ...and switching that row off takes the section it names with it. */
    public function test_a_disabled_row_hides_the_section_it_names(): void
    {
        $this->laptop->update(['description' => ['en' => 'A thin laptop.']]);
        $this->asSections();

        ProductTab::create([
            'section' => 'description', 'title' => ['en' => 'Description'],
            'enabled' => false, 'sort' => 10, 'rules_match' => 'all',
        ]);

        // The section itself, not its words: the description is also the page's
        // meta description, which is in the head whatever the page draws below.
        $body = $this->get('http://localhost/product/tab-1')->assertOk()->getContent();

        $this->assertStringNotContainsString('id="description"', $body);
    }

    /**
     * The rule this was asked for: a tab that stands in for a description the
     * product has not got. A rule kind rather than a switch on the tab, so a
     * block or a motivation card can ask the same question.
     */
    public function test_a_tab_can_stand_in_for_a_missing_description(): void
    {
        ProductTab::create([
            'title' => ['en' => 'About this kind of thing'],
            'content' => ['en' => '<p>Laptops in general.</p>'],
            'enabled' => true, 'sort' => 10, 'rules_match' => 'all',
            'rules' => [['kind' => 'product_content', 'operator' => 'empty', 'value' => ['description']]],
        ]);

        // TAB-1 has no description yet, so the stand-in is drawn.
        $this->get('http://localhost/product/tab-1')->assertOk()->assertSee('Laptops in general.', false);

        $this->laptop->update(['description' => ['en' => 'A thin laptop.']]);

        $this->get('http://localhost/product/tab-1')->assertOk()
            ->assertDontSee('Laptops in general.', false)
            ->assertSee('A thin laptop.');
    }

    /** An editor's leftover markup is not a description, and the rule agrees. */
    public function test_an_empty_paragraph_is_not_a_description(): void
    {
        $this->laptop->update(['description' => ['en' => '<p></p>']]);

        ProductTab::create([
            'title' => ['en' => 'Stand-in'], 'content' => ['en' => '<p>Something.</p>'],
            'enabled' => true, 'sort' => 10, 'rules_match' => 'all',
            'rules' => [['kind' => 'product_content', 'operator' => 'empty', 'value' => ['description']]],
        ]);

        $this->get('http://localhost/product/tab-1')->assertOk()->assertSee('Something.', false);
    }

    /** ...and the other way round, about what the product does have. */
    public function test_the_rule_also_asks_what_is_there(): void
    {
        ProductTab::create([
            'title' => ['en' => 'Specs note'], 'content' => ['en' => '<p>Measured by us.</p>'],
            'enabled' => true, 'sort' => 30, 'rules_match' => 'all',
            'rules' => [['kind' => 'product_content', 'operator' => 'filled', 'value' => ['characteristics']]],
        ]);

        $this->get('http://localhost/product/tab-1')->assertOk()->assertDontSee('Measured by us.', false);

        $feature = Feature::create([
            'name' => ['en' => 'Weight'], 'code' => 'tab_weight', 'purpose' => 'info', 'input_type' => 'text',
        ]);
        ProductFeature::create([
            'product_id' => $this->laptop->id, 'feature_id' => $feature->id, 'value_text' => '1.2 kg',
        ]);

        $this->get('http://localhost/product/tab-1')->assertOk()->assertSee('Measured by us.', false);
    }

    public function test_the_admin_screens_render(): void
    {
        $tab = ProductTab::create([
            'product_id' => $this->laptop->id,
            'title' => ['en' => 'Care'], 'content' => ['en' => '<p>Dry.</p>'],
            'enabled' => true, 'sort' => 50, 'rules_match' => 'all',
        ]);

        $this->actingAs($this->admin());

        $this->get('http://localhost/admin/catalog/product-tabs')->assertOk()->assertSee('Care');
        $this->get('http://localhost/admin/catalog/product-tabs/create?product='.$this->laptop->id)->assertOk();
        $this->get('http://localhost/admin/catalog/product-tabs/'.$tab->id.'/edit')->assertOk()->assertSee('Care');
        // ...and the product's own form lists it.
        $this->get('http://localhost/admin/catalog/products/'.$this->laptop->id.'/edit')->assertOk()->assertSee('Care');
    }

    /**
     * A long description folds at the shop's height and the characteristics
     * only when asked — the fold itself is Alpine's, the page carries the
     * numbers it measures by.
     */
    public function test_the_description_and_the_characteristics_fold_at_the_shops_height(): void
    {
        $this->laptop->update(['description' => ['en' => '<p>A long story.</p>']]);
        $feature = Feature::create([
            'name' => ['en' => 'Memory'], 'code' => 'fold_ram', 'purpose' => 'info', 'input_type' => 'text',
        ]);
        ProductFeature::create([
            'product_id' => $this->laptop->id, 'feature_id' => $feature->id, 'value_text' => '16 GB',
        ]);

        $page = $this->get('http://localhost/product/tab-1')->assertOk();
        $page->assertSee('max-height: 420px', false)->assertSee('Show the whole description');
        $page->assertDontSee('Show all characteristics');

        $settings = app(SettingsManager::class);
        $settings->set('torgash.catalog', 'description_fold_height', 0);
        $settings->set('torgash.catalog', 'specs_fold_height', 300);

        $page = $this->get('http://localhost/product/tab-1')->assertOk();
        $page->assertSee('A long story.')->assertDontSee('Show the whole description');
        $page->assertSee('max-height: 300px', false)->assertSee('Show all characteristics');
    }

    protected function product(string $sku, string $name): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => 1000_00, 'stock' => 5, 'status' => 'active',
        ]);
    }

    /** The shop switches the layout; what the list of sections holds does not. */
    protected function asSections(): void
    {
        app(SettingsManager::class)->set('torgash.catalog', 'product_details_mode', 'sections');
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
