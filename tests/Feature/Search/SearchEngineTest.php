<?php

namespace Tests\Feature\Search;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Search\Engine;
use Torgash\Search\Layout;
use Torgash\Search\Pending;
use Torgash\Search\SearchQuery;

/**
 * The search engine (PLAN §34, step 1): what a customer types finds what they
 * meant — by the start of a word, by another form of it, in another language,
 * on the wrong keyboard, by SKU and brand — and only what they may be shown.
 */
class SearchEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The seeded catalogue would answer too; these tests speak about their
        // own products only.
        DB::table('m_search_documents')->delete();
        DB::table('m_search_words')->delete();
    }

    public function test_the_start_of_a_word_finds_the_product(): void
    {
        $laptop = $this->product('SRCH-1', ['ru' => 'Ноутбук Zenith 14', 'en' => 'Zenith 14 laptop']);

        $this->assertSame([$laptop->id], $this->search('ноут'));
        $this->assertSame([$laptop->id], $this->search('zen'));
    }

    /** "ноутбуки" and "laptops" are the same words as "ноутбук" and "laptop". */
    public function test_another_form_of_the_word_finds_it_in_its_own_language(): void
    {
        $laptop = $this->product('SRCH-2', ['ru' => 'Ноутбук Zenith', 'en' => 'Zenith laptop']);

        $this->assertSame([$laptop->id], $this->search('ноутбуки'));
        $this->assertSame([$laptop->id], $this->search('laptops'));
    }

    /** The old column held English and Russian only; every translation counts now. */
    public function test_a_name_in_any_language_is_searched(): void
    {
        $kettle = $this->product('SRCH-3', ['en' => 'Kettle', 'uk' => 'Чайник Сонечко']);

        $this->assertSame([$kettle->id], $this->search('сонеч'));
    }

    public function test_every_word_must_match(): void
    {
        $this->product('SRCH-4', ['en' => 'Steel frying pan']);
        $wok = $this->product('SRCH-5', ['en' => 'Steel wok']);

        $this->assertSame([$wok->id], $this->search('steel wok'));
    }

    public function test_an_exact_sku_comes_before_a_name_that_merely_mentions_it(): void
    {
        $mention = $this->product('OTHER-9', ['en' => 'Case for AB-100 and friends']);
        $exact = $this->product('AB-100', ['en' => 'Plain widget']);

        $this->assertSame([$exact->id, $mention->id], $this->search('ab-100'));
        // Postgres reads "AB-100" as `ab` and a negative number; the SKU is
        // also found by its digits and run together.
        $this->assertContains($exact->id, $this->search('100'));
        $this->assertSame([$exact->id], $this->search('ab100'));
    }

    public function test_the_wrong_keyboard_layout_is_read_and_said(): void
    {
        $laptop = $this->product('SRCH-6', ['ru' => 'Ноутбук Zenith']);

        $result = app(Engine::class)->search('yjen,er');

        $this->assertSame([$laptop->id], $result->ids);
        $this->assertSame('layout', $result->how);
        $this->assertSame('ноутбук', $result->readAs);
        $this->assertContains('ноутбук', Layout::alternatives('yjen,er'));
    }

    public function test_a_typo_is_corrected_by_the_catalogues_words_and_says_so(): void
    {
        $laptop = $this->product('SRCH-7', ['en' => 'Laptop Zenith']);

        $result = app(Engine::class)->search('lapotp');

        $this->assertSame([$laptop->id], $result->ids);
        $this->assertSame('typo', $result->how);
        $this->assertSame('laptop', $result->readAs);
    }

    public function test_a_brand_is_searched_and_a_characteristic_only_when_marked(): void
    {
        $brand = Feature::create(['name' => ['en' => 'Brand'], 'code' => 'srch_brand', 'purpose' => 'brand', 'input_type' => 'select']);
        $colour = Feature::create(['name' => ['en' => 'Colour'], 'code' => 'srch_colour', 'purpose' => 'filter', 'input_type' => 'select', 'shown_in' => ['filters']]);
        $acme = FeatureValue::create(['feature_id' => $brand->id, 'value' => ['en' => 'Acmeflux']]);
        $teal = FeatureValue::create(['feature_id' => $colour->id, 'value' => ['en' => 'Tealish']]);

        $product = $this->product('SRCH-8', ['en' => 'Plain kettle']);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $brand->id, 'feature_value_id' => $acme->id]);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $colour->id, 'feature_value_id' => $teal->id]);
        app(Pending::class)->flush();

        $this->assertSame([$product->id], $this->search('acmeflux'));
        $this->assertSame([], $this->search('tealish'), 'not marked for search');

        // Marking it is saving the characteristic — its products are rebuilt.
        $colour->update(['shown_in' => ['filters', 'search']]);
        app(Pending::class)->flush();

        $this->assertSame([$product->id], $this->search('tealish'));
    }

    public function test_renaming_a_value_rebuilds_the_products_that_carry_it(): void
    {
        $brand = Feature::create(['name' => ['en' => 'Brand'], 'code' => 'srch_brand2', 'purpose' => 'brand', 'input_type' => 'select']);
        $value = FeatureValue::create(['feature_id' => $brand->id, 'value' => ['en' => 'Oldname']]);
        $product = $this->product('SRCH-9', ['en' => 'Kettle']);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $brand->id, 'feature_value_id' => $value->id]);
        app(Pending::class)->flush();

        $value->update(['value' => ['en' => 'Newname']]);
        app(Pending::class)->flush();

        $this->assertSame([$product->id], $this->search('newname'));
        $this->assertSame([], $this->search('oldname'));
    }

    /** Only what this visitor may be shown — a disabled product is not an answer. */
    public function test_a_product_that_is_not_listed_is_not_found(): void
    {
        $shown = $this->product('SRCH-10', ['en' => 'Visible gadget']);
        $this->product('SRCH-11', ['en' => 'Hidden gadget'], ['status' => 'disabled']);

        $this->assertSame([$shown->id], $this->search('gadget'));
    }

    public function test_sold_out_goes_last_when_the_catalogue_says_so(): void
    {
        app(SettingsManager::class)->set('torgash.catalog', 'out_of_stock_last', true);

        $soldOut = $this->product('SRCH-12', ['en' => 'Gizmo'], ['stock' => 0, 'availability_status' => 'out_of_stock']);
        $inStock = $this->product('SRCH-13', ['en' => 'Gizmo deluxe'], ['stock' => 5]);

        $this->assertSame([$inStock->id, $soldOut->id], $this->search('gizmo'));
    }

    /** Nothing a customer types reaches Postgres as a tsquery operator. */
    public function test_operators_in_the_query_are_just_characters(): void
    {
        $this->product('SRCH-14', ['en' => 'Plain thing']);

        $this->assertSame(['plain', 'thing'], (new SearchQuery("plain & | ! ( ) :* 'thing'"))->words);
        $this->assertCount(1, $this->search("plain & | ! ( ) :* 'thing'"));
    }

    /**
     * The real path: a save only marks the product, and its document is
     * rebuilt once, when the request that saved it ends.
     */
    public function test_a_saved_product_is_rebuilt_when_the_request_ends(): void
    {
        config(['torgash.search.batch_in_tests' => true]);
        $product = $this->product('SRCH-18', ['en' => 'Zanzibar kettle']);
        app(Pending::class)->flush();

        $product->update(['name' => ['en' => 'Quokka kettle']]);
        $this->assertSame([], $this->search('quokka'), 'marked, not yet rebuilt');

        $this->get('http://localhost/')->assertOk();

        $this->assertSame([$product->id], $this->search('quokka'));
    }

    // -------------------------------------------------------------- the page

    public function test_the_results_page_is_answered_by_the_engine_and_says_how_it_read_the_query(): void
    {
        $this->product('SRCH-15', ['ru' => 'Ноутбук Zenith', 'en' => 'Zenith laptop']);

        $this->get('http://localhost/search?q=yjen%2Cer')
            ->assertOk()
            ->assertSee('Zenith laptop')
            ->assertSee('ноутбук');
    }

    /**
     * The results are the catalogue's listing over the engine's answer: best
     * match first under "Relevance" until another order is picked, the
     * panel's filters narrow the answer, and `q` rides every submit.
     */
    public function test_the_results_page_has_the_catalogues_panel(): void
    {
        $this->product('SRCH-21', ['en' => 'Zenith 14 laptop'], ['price' => 900_00]);
        $this->product('SRCH-22', ['en' => 'Zenith dock for the laptop'], ['price' => 50_00]);

        $page = $this->get('http://localhost/search?q=zenith')->assertOk();
        $page->assertSee('<input type="hidden" name="q" value="zenith">', false);
        $page->assertSee('Relevance');
        $this->assertMatchesRegularExpression('#name="sort" value="relevance"[^>]*checked#', $page->getContent());

        $this->get('http://localhost/search?q=zenith&price_max=100')->assertOk()
            ->assertSee('SRCH-22')->assertDontSee('SRCH-21');

        $cheapFirst = $this->get('http://localhost/search?q=zenith&sort=price_asc')->assertOk()->getContent();
        $this->assertLessThan(strpos($cheapFirst, 'SRCH-21'), strpos($cheapFirst, 'SRCH-22'));
    }

    public function test_the_admin_screen_tries_a_query_and_rebuilds(): void
    {
        $this->product('SRCH-16', ['en' => 'Zenith laptop']);
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/search-engine?q=zenith')->assertOk()->assertSee('Zenith laptop');
        $this->actingAs($admin)->post('http://localhost/admin/search-engine/reindex')->assertRedirect();
        $this->actingAs(User::factory()->create())->get('http://localhost/admin/search-engine')->assertForbidden();
    }

    public function test_the_command_rebuilds_every_document(): void
    {
        $product = $this->product('SRCH-17', ['en' => 'Zenith laptop']);
        DB::table('m_search_documents')->delete();

        $this->artisan('search:reindex')->assertSuccessful();

        $this->assertTrue(DB::table('m_search_documents')->where('product_id', $product->id)->exists());
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<int> */
    protected function search(string $q): array
    {
        return app(Engine::class)->search($q)->ids;
    }

    /**
     * @param  array<string, string>  $name
     * @param  array<string, mixed>  $extra
     */
    protected function product(string $sku, array $name, array $extra = []): Product
    {
        $product = Product::create($extra + [
            'sku' => $sku,
            'name' => $name,
            'slug' => strtolower($sku),
            'price' => 100_00,
            'stock' => 10,
            'status' => 'active',
        ]);

        app(Pending::class)->flush();

        return $product;
    }
}
