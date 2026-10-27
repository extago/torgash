<?php

namespace Tests\Feature\Search;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Pages\Models\Page;
use Torgash\Search\Highlight;

/**
 * What the shop decides about the window (PLAN §34, after step 3): which
 * parts of a product row are shown, the buy button and its "in cart" state,
 * what was typed in bold, more products while scrolling, and searching the
 * whole site or the catalogue alone.
 */
class WindowOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('m_search_documents')->delete();
        DB::table('m_search_words')->delete();
    }

    public function test_each_part_of_a_product_row_can_be_switched_off(): void
    {
        $this->product('OPT-1', 'Zenith kettle');
        $url = 'http://localhost/search/suggest?q=zenith';

        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('In stock', $html);
        $this->assertStringContainsString(route('cart.add', 'opt-1'), $html);
        $this->assertStringNotContainsString('OPT-1', strip_tags($html), 'SKUs are off by default');

        foreach (['suggest_prices', 'suggest_stock', 'suggest_add_to_cart', 'suggest_images'] as $key) {
            $this->setting($key, false);
        }
        $this->setting('suggest_skus', true);

        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('In stock', $html);
        $this->assertStringNotContainsString(route('cart.add', 'opt-1'), $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('OPT-1', strip_tags($html));
    }

    /** Both states are drawn; the one showing is what the basket holds. */
    public function test_a_product_already_in_the_basket_shows_in_cart(): void
    {
        $this->product('OPT-2', 'Zenith kettle');
        $url = 'http://localhost/search/suggest?q=zenith';

        $before = $this->get($url)->getContent();
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*data-live-search-add[^>]*class="hidden"/', $before);

        $this->post(route('cart.add', 'opt-2'), ['qty' => 1], ['HX-Request' => 'true'])->assertOk();

        $after = $this->get($url)->getContent();
        $this->assertMatchesRegularExpression('/<form[^>]*data-live-search-add[^>]*class="hidden"/', $after);
        $this->assertMatchesRegularExpression('/data-live-search-in-cart[^>]*class="btn btn-success btn-sm btn-square"/', $after);
    }

    public function test_what_was_typed_is_bold_at_the_start_of_a_word_and_nowhere_else(): void
    {
        $this->assertSame('<b>Ноут</b>бук Zenith', (string) Highlight::html('Ноутбук Zenith', ['ноут']));
        // Another form of the word shares its beginning.
        $this->assertSame('<b>Ноутбук</b> Zenith', (string) Highlight::html('Ноутбук Zenith', ['ноутбуки']));
        // Not the middle of a word: the engine never matched it there.
        $this->assertSame('Масло для кутикулы', (string) Highlight::html('Масло для кутикулы', ['тик']));
        // Escaped — only <b> is added.
        $this->assertSame('&lt;i&gt; <b>zen</b>ith', (string) Highlight::html('<i> zenith', ['zen']));

        $this->product('OPT-3', 'Zenith kettle');
        $this->get('http://localhost/search/suggest?q=zen')->assertSee('<b>Zen</b>ith kettle', false);

        $this->setting('suggest_highlight', false);
        $this->get('http://localhost/search/suggest?q=zen')->assertDontSee('<b>Zen</b>', false);
    }

    /** A typo is bold as what it was READ as — that is what matched. */
    public function test_a_corrected_query_is_bold_as_corrected(): void
    {
        $this->product('OPT-4', 'Zenith kettle');

        $this->get('http://localhost/search/suggest?q=zentih')->assertSee('<b>Zenith</b> kettle', false);
    }

    public function test_more_products_arrive_in_batches_while_scrolling(): void
    {
        $this->setting('suggest_products', 2);

        foreach (range(1, 5) as $i) {
            $this->product('OPT-B'.$i, 'Batchable item '.$i);
        }

        $first = $this->get('http://localhost/search/suggest?q=batchable')->assertOk()->getContent();
        $this->assertSame(2, substr_count($first, 'role="option" class="flex items-center'));
        $this->assertMatchesRegularExpression('/data-live-search-more="[^"]*offset=2/', $first);

        $second = $this->get('http://localhost/search/suggest?q=batchable&offset=2')->assertOk()->getContent();
        $this->assertSame(2, substr_count($second, 'role="option"'));
        $this->assertStringNotContainsString('Products</div>', $second, 'a batch is rows only');
        $this->assertMatchesRegularExpression('/data-live-search-more="[^"]*offset=4/', $second);

        $last = $this->get('http://localhost/search/suggest?q=batchable&offset=4')->assertOk()->getContent();
        $this->assertSame(1, substr_count($last, 'role="option"'));
        $this->assertStringNotContainsString('data-live-search-more', $last);

        $this->setting('suggest_load_more', false);
        $this->assertStringNotContainsString('data-live-search-more', $this->get('http://localhost/search/suggest?q=batchable')->getContent());
    }

    /**
     * The catalogue by default; the site — its pages — when chosen, and the
     * choice remembered for the visit.
     */
    public function test_the_visitor_chooses_the_catalogue_or_the_site_and_it_is_remembered(): void
    {
        $this->product('OPT-5', 'Delivery drone');
        Page::create(['title' => ['en' => 'Delivery and payment'], 'slug' => 'opt-delivery', 'enabled' => true]);

        // The switch is inside the header's search form, the catalogue ticked.
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="scope" value="catalog"[^>]*checked/', $home);
        $this->assertDoesNotMatchRegularExpression('/name="scope" value="site"[^>]*checked/', $home);

        $this->get('http://localhost/search/suggest?q=delivery')->assertOk()
            ->assertSeeText('Delivery drone')
            ->assertDontSeeText('Delivery and payment');

        $site = strip_tags($this->get('http://localhost/search/suggest?q=delivery&scope=site')->assertOk()->getContent());
        $this->assertStringContainsString('Delivery and payment', $site);
        $this->assertStringNotContainsString('Delivery drone', $site, 'the site is its pages');

        // Remembered for the visit, with no parameter — the results page too.
        $this->get('http://localhost/search?q=delivery')->assertOk()
            ->assertSeeText('Delivery and payment')
            ->assertDontSeeText('Delivery drone');
        // Back to the catalogue: its one product, found as typed, is the answer.
        $this->get('http://localhost/search?q=delivery&scope=catalog')->assertRedirect(route('catalog.product', 'opt-5'));
    }

    public function test_the_site_can_show_the_catalogue_beside_its_pages(): void
    {
        $this->setting('site_includes_catalog', true);
        $this->product('OPT-6', 'Delivery drone');
        Page::create(['title' => ['en' => 'Delivery and payment'], 'slug' => 'opt-delivery-3', 'enabled' => true]);

        $this->get('http://localhost/search?q=delivery&scope=site')->assertOk()
            ->assertSeeText('Delivery and payment')
            ->assertSeeText('Delivery drone');
    }

    /** "Found nothing" is about products — a search of pages alone is not counted. */
    public function test_a_search_of_pages_alone_is_not_counted(): void
    {
        $this->get('http://localhost/search?q=delivery&scope=site')->assertOk();

        $this->assertSame(0, DB::table('m_search_stats')->count());
    }

    public function test_a_shop_searching_the_catalogue_alone_offers_no_switch(): void
    {
        $this->setting('search_site', false);
        Page::create(['title' => ['en' => 'Delivery and payment'], 'slug' => 'opt-delivery-2', 'enabled' => true]);

        $this->get('http://localhost/')->assertOk()->assertDontSee('name="scope"', false);
        $this->get('http://localhost/search/suggest?q=delivery&scope=site')->assertDontSeeText('Delivery and payment');
    }

    protected function setting(string $key, mixed $value): void
    {
        app(SettingsManager::class)->set('torgash.search', $key, $value);
    }

    protected function product(string $sku, string $name): Product
    {
        return Product::create([
            'sku' => $sku,
            'name' => ['en' => $name],
            'slug' => strtolower($sku),
            'price' => 100_00,
            'stock' => 10,
            'status' => 'active',
        ]);
    }
}
