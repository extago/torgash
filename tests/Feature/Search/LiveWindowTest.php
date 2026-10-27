<?php

namespace Tests\Feature\Search;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Pages\Models\Page;

/**
 * The window under the search box (PLAN §34, step 2) and what the results
 * page learnt with it: narrowing to a category, opening the one product
 * found, remembering what the visitor searched for.
 */
class LiveWindowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('m_search_documents')->delete();
        DB::table('m_search_words')->delete();
    }

    public function test_the_window_shows_products_with_price_stock_and_a_buy_button(): void
    {
        $this->product('WIN-1', 'Zenith laptop');

        $html = $this->get('http://localhost/search/suggest?q=zeni')->assertOk()->getContent();

        $this->assertStringContainsString('Zenith laptop', $html);
        $this->assertStringContainsString('role="option"', $html);
        // The buy button posts to the cart and swaps nothing itself — the
        // answer brings the badge out of band, as for every buy form.
        $this->assertStringContainsString('hx-post="'.route('cart.add', 'win-1').'"', $html);
        $this->assertStringContainsString('hx-swap="none"', $html);
    }

    public function test_a_sold_out_product_gets_no_buy_button(): void
    {
        $this->product('WIN-2', 'Zenith kettle', ['stock' => 0, 'availability_status' => 'out_of_stock']);

        $html = $this->get('http://localhost/search/suggest?q=zenith')->assertOk()->getContent();

        $this->assertStringContainsString('Zenith kettle', strip_tags($html));
        $this->assertStringNotContainsString(route('cart.add', 'win-2'), $html);
    }

    public function test_the_categories_of_what_was_found_narrow_the_search(): void
    {
        // One product will be left; opening it is the next test's subject.
        app(SettingsManager::class)->set('torgash.search', 'redirect_single', false);

        $laptops = Category::create(['name' => ['en' => 'Laptops'], 'slug' => 'win-laptops', 'enabled' => true]);
        $bags = Category::create(['name' => ['en' => 'Bags'], 'slug' => 'win-bags', 'enabled' => true]);
        $laptop = $this->product('WIN-3', 'Zenith laptop');
        $bag = $this->product('WIN-4', 'Zenith laptop bag');
        $laptop->categories()->attach($laptops);
        $bag->categories()->attach($bags);

        $html = $this->get('http://localhost/search/suggest?q=zenith')->assertOk()->getContent();
        $this->assertStringContainsString(e(route('catalog.search', ['q' => 'zenith', 'in' => 'win-bags'])), $html);

        $page = $this->get('http://localhost/search?q=zenith&in=win-bags')->assertOk();
        $page->assertSee('Zenith laptop bag')->assertSee('Bags');
        $this->assertStringNotContainsString('>Zenith laptop<', $page->getContent());
    }

    public function test_information_pages_answer_through_their_own_module(): void
    {
        Page::create(['title' => ['en' => 'Delivery and payment'], 'slug' => 'win-delivery', 'enabled' => true]);

        $this->get('http://localhost/search/suggest?q=delivery&scope=site')->assertOk()->assertSeeText('Delivery and payment');
    }

    public function test_recent_searches_appear_before_anything_is_typed_and_can_be_cleared(): void
    {
        $this->product('WIN-5', 'Zenith laptop');
        $this->product('WIN-6', 'Zenith phone');

        $this->get('http://localhost/search?q=zenith')->assertOk();

        $this->get('http://localhost/search/suggest?q=')->assertOk()->assertSee('zenith');

        $this->post('http://localhost/search/recent/forget')->assertOk();
        $this->assertSame('', trim($this->get('http://localhost/search/suggest?q=')->getContent()));
    }

    public function test_one_product_found_as_typed_opens_its_page(): void
    {
        $this->product('WIN-7', 'Quokkaphone');

        $this->get('http://localhost/search?q=quokkaphone')->assertRedirect(route('catalog.product', 'win-7'));
    }

    /** Never after reading the query some other way — that would be a guess. */
    public function test_one_product_found_by_a_typo_is_shown_not_opened(): void
    {
        $this->product('WIN-8', 'Quokkaphone');

        $this->get('http://localhost/search?q=quokaphone')->assertOk()->assertSee('Quokkaphone');
    }

    public function test_the_script_is_on_every_storefront_page_and_the_window_is_private(): void
    {
        $this->get('http://localhost/')->assertOk()
            ->assertSee('/modules/torgash.search/assets/live-search.js', false)
            ->assertSee('data-live-search', false);

        // `Disallow: /search` is a prefix: it covers /search/suggest as well.
        $this->get('http://localhost/robots.txt')->assertOk()->assertSee('Disallow: /search');
    }

    public function test_the_window_is_rate_limited(): void
    {
        $this->assertTrue(
            collect(app('router')->getRoutes()->getByName('search.suggest')->gatherMiddleware())->contains('throttle:torgash-search'),
        );
    }

    /** @param  array<string, mixed>  $extra */
    protected function product(string $sku, string $name, array $extra = []): Product
    {
        return Product::create($extra + [
            'sku' => $sku,
            'name' => ['en' => $name],
            'slug' => strtolower($sku),
            'price' => 100_00,
            'stock' => 10,
            'status' => 'active',
        ]);
    }
}
