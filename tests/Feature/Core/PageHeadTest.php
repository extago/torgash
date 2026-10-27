<?php

namespace Tests\Feature\Core;

use App\Core\Seo\NormaliseUrl;
use App\Core\Settings\SettingsManager;
use Illuminate\Http\Request;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;

/**
 * The head of a storefront page — the half of SEO that is not a module.
 *
 * Everything pinned here was missing outright before: there was no canonical,
 * no hreflang, no meta description, no OpenGraph and no meta robots, so a
 * two-language shop published every page twice and said nothing about which
 * copy was which. The properties that are easy to break without noticing are
 * the ones with a test each: that there is exactly ONE of each tag, that a
 * paginated address points at itself, that a language that does not exist in
 * the route table is not advertised, and that the list of pages hidden from an
 * index is the same list in the page and in robots.txt.
 */
class PageHeadTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'HEAD-1',
            'name' => ['en' => 'Head widget', 'ru' => 'Виджет'],
            'slug' => 'head-widget',
            'description' => ['en' => '<p>A <strong>widget</strong> for heads.</p>'],
            'price' => 12_00,
            'stock' => 3,
            'status' => 'active',
        ]);
    }

    public function test_a_product_page_says_what_it_is(): void
    {
        $body = $this->get('http://localhost/product/head-widget')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Head widget · ', $body);
        $this->assertStringContainsString('<meta name="description" content="A widget for heads."', $body);
        $this->assertStringContainsString('<link rel="canonical" href="http://localhost/product/head-widget">', $body);
        $this->assertStringContainsString('<meta property="og:type" content="product">', $body);
        $this->assertStringContainsString('<meta property="og:url" content="http://localhost/product/head-widget">', $body);

        // One of each. Two canonicals is a page giving a search engine two
        // answers to the same question, which is the failure this whole design
        // exists to make impossible.
        $this->assertSame(1, substr_count($body, 'rel="canonical"'));
        $this->assertSame(1, substr_count($body, '<title>'));
        $this->assertSame(1, substr_count($body, 'application/ld+json'));
    }

    /**
     * A shop with no description written for a product still has something
     * true to say about it — an empty meta description is the commonest fault
     * in a real catalogue.
     */
    public function test_a_product_with_no_description_still_gets_one(): void
    {
        $this->product->update(['description' => ['en' => '']]);

        // The composed sentence (name, brand, characteristics, price); the
        // price is written the currency's way, a no-break space included.
        $this->get('http://localhost/product/head-widget')->assertOk()
            ->assertSee('<meta name="description" content="Head widget. Price 12.00', false);
    }

    /**
     * Page two is its own page. The common recipe points it at page one, which
     * hides every product that is not on it.
     */
    public function test_a_paginated_address_is_its_own_canonical_and_tracking_is_dropped(): void
    {
        // Enough products for a page two: past the last page is a 404 now.
        foreach (range(1, app(\Torgash\Catalog\Support\ListingView::class)->defaultPerPage()) as $i) {
            Product::create(['sku' => "HEAD-P{$i}", 'name' => ['en' => "Filler {$i}"], 'slug' => "head-filler-{$i}", 'price' => 1_00, 'stock' => 1, 'status' => 'active']);
        }

        $body = $this->get('http://localhost/catalog?page=2&utm_source=newsletter')->assertOk()->getContent();

        // The tracking parameter survives in the paginator's own links (they
        // keep the query string, which is right) and in nothing that speaks to
        // a crawler: not the canonical, not an alternate, not og:url.
        $this->assertStringContainsString('<link rel="canonical" href="http://localhost/catalog?page=2">', $body);
        $this->assertStringContainsString('<meta property="og:url" content="http://localhost/catalog?page=2">', $body);
        $this->assertStringNotContainsString('utm_source', substr($body, 0, (int) strpos($body, '</head>')));

        // rel=next/prev is not printed at all — no search engine has used it
        // since 2019, and it is one more pair of tags to keep in step.
        $this->assertStringNotContainsString('rel="next"', $body);
    }

    public function test_the_languages_of_a_page_point_at_each_other(): void
    {
        $body = $this->get('http://localhost/product/head-widget')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="http://localhost/product/head-widget">', $body);
        $this->assertStringContainsString('<link rel="alternate" hreflang="ru" href="http://localhost/ru/product/head-widget">', $body);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="http://localhost/product/head-widget">', $body);

        // ...and from the other side, at the same pair — an alternate that
        // disagrees with the page it points at is ignored wholesale.
        $russian = $this->get('http://localhost/ru/product/head-widget')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="http://localhost/ru/product/head-widget">', $russian);
        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="http://localhost/product/head-widget">', $russian);
    }

    /**
     * The registry of pages that are not for an index, read by both readers:
     * the page itself and robots.txt. One list, or the two drift.
     */
    public function test_a_private_page_says_noindex_and_is_in_robots(): void
    {
        // This test is about the RESULTS page; with one product found the
        // search module would open the product instead (redirect_single).
        app(\App\Core\Settings\SettingsManager::class)->set('torgash.search', 'redirect_single', false);

        $this->get('http://localhost/cart')->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $this->get('http://localhost/search?q=widget')->assertOk()->assertSee('name="robots"', false);

        $robots = $this->get('http://localhost/robots.txt')->getContent();

        $this->assertStringContainsString('Disallow: /cart', $robots);
        $this->assertStringContainsString('Disallow: /ru/cart', $robots);

        // ...and an ordinary page says nothing, because `index, follow` is what
        // a crawler already assumed.
        $this->get('http://localhost/product/head-widget')->assertOk()->assertDontSee('name="robots"', false);
    }

    /** A search page is the visitor's question, so the question stays in the address. */
    public function test_a_search_page_keeps_its_query_in_the_canonical(): void
    {
        // This test is about the RESULTS page; with one product found the
        // search module would open the product instead (redirect_single).
        app(\App\Core\Settings\SettingsManager::class)->set('torgash.search', 'redirect_single', false);

        $this->get('http://localhost/search?q=widget')->assertOk()
            ->assertSee('<link rel="canonical" href="http://localhost/search?q=widget">', false);
    }

    /**
     * One graph, not three scripts: the Product, the trail to it and the shop
     * itself are connected only when they are in the same one.
     */
    public function test_the_structured_data_is_one_graph(): void
    {
        $category = Category::create(['name' => ['en' => 'Widgets'], 'slug' => 'widgets', 'enabled' => true]);
        $this->product->categories()->attach($category->id);

        $body = $this->get('http://localhost/product/head-widget')->assertOk()->getContent();

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $match);
        $graph = json_decode($match[1] ?? '', true);

        $this->assertSame('https://schema.org', $graph['@context'] ?? null);

        $types = array_column($graph['@graph'] ?? [], '@type');

        $this->assertContains('Product', $types);
        $this->assertContains('Organization', $types);
        $this->assertContains('WebSite', $types);
        $this->assertContains('BreadcrumbList', $types);

        $crumbs = collect($graph['@graph'])->firstWhere('@type', 'BreadcrumbList');

        $this->assertSame(
            ['Home', 'Catalog', 'Widgets', 'Head widget'],
            array_column($crumbs['itemListElement'], 'name'),
        );

        // The last crumb is the page itself and carries no URL — a link to
        // where you are is noise to a reader and a self-reference in the graph.
        $this->assertArrayNotHasKey('item', end($crumbs['itemListElement']));
    }

    /** The trail a visitor reads is the same list the graph is built from. */
    public function test_the_printed_trail_matches(): void
    {
        $this->get('http://localhost/product/head-widget')->assertOk()
            ->assertSee('breadcrumbs', false)
            ->assertSee('Head widget');
    }

    /** Ten lines without which analytics, a chat widget or a gateway's JS can only get in by patching a theme. */
    public function test_a_module_can_reach_the_head_the_body_and_the_footer(): void
    {
        hook_listen('core.head', fn ($html) => $html.'<meta name="verify" content="x">');
        hook_listen('core.body_start', fn ($html) => $html.'<!--pixel-->');
        hook_listen('core.footer', fn ($html) => $html.'<!--chat-->');

        $this->get('http://localhost/product/head-widget')->assertOk()
            ->assertSee('<meta name="verify" content="x">', false)
            ->assertSee('<!--pixel-->', false)
            ->assertSee('<!--chat-->', false);
    }

    /**
     * The last word in the chain, and it has to be asked at print time: a
     * listener that overrides the title must be able to see the title
     * everything else settled on.
     */
    public function test_the_meta_filter_has_the_last_word(): void
    {
        hook_listen('core.page.meta', function (array $values) {
            $values['title'] = 'Rewritten: '.($values['title'] ?? '');

            return $values;
        });

        $this->get('http://localhost/product/head-widget')->assertOk()
            ->assertSee('<title>Rewritten: Head widget · ', false);
    }

    /**
     * Nothing about an address changes until the shop says so — and then a
     * duplicate address answers with a 301 instead of a second copy of the
     * page.
     *
     * Exercised through the middleware itself rather than the test client:
     * Laravel's own helper trims a trailing slash off a URL before it ever
     * becomes a request, so a test that went through get() would be testing
     * the helper.
     */
    public function test_url_normalisation_is_off_until_switched_on(): void
    {
        $settings = app(SettingsManager::class);

        $this->assertNull($this->normalise('/catalog/'));
        $this->assertNull($this->normalise('/Catalog'));

        $settings->set('core', 'url_strip_trailing_slash', true);
        $settings->set('core', 'url_lowercase', true);
        $settings->set('core', 'url_collapse_slashes', true);

        $this->assertSame('http://localhost/catalog', $this->normalise('/catalog/'));
        $this->assertSame('http://localhost/catalog', $this->normalise('/Catalog'));
        $this->assertSame('http://localhost/catalog/widgets', $this->normalise('/catalog//widgets'));

        // The query is not the shop's to tidy, and an address that is already
        // right is not redirected at all — a 301 to itself is a loop.
        $this->assertSame('http://localhost/catalog?q=Widget', $this->normalise('/Catalog?q=Widget'));
        $this->assertNull($this->normalise('/catalog'));

        // A POST is never redirected: a 301 would lose the body. Nor is the
        // admin, whose addresses are nobody's to index.
        $this->assertNull($this->normalise('/Catalog', 'POST'));
        $this->assertNull($this->normalise('/admin/Catalog'));
    }

    /** Where the middleware would send this address, or null for "it is already right". */
    protected function normalise(string $uri, string $method = 'GET'): ?string
    {
        $response = (new NormaliseUrl)->handle(
            Request::create('http://localhost'.$uri, $method),
            fn () => response('next'),
        );

        return $response->isRedirect() ? $response->headers->get('Location') : null;
    }
}
