<?php

namespace Tests\Feature\Core;

use App\Core\PageCache\PageCache;
use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RunsInOctaneWorker;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;

/**
 * The shop's own pages kept for guests (PLAN §61), drawn by the real
 * modules: the home page, the catalogue, a category and a product are kept
 * and served to the next guest — and what one guest built up (a basket, a
 * wishlist, a town, the products they looked at) is theirs alone on the
 * page every guest is served.
 *
 * Every copy here is taken BEFORE a guest acts, as on a live shop where
 * somebody else opened the page first.
 */
class PageCacheStorefrontTest extends TestCase
{
    use RunsInOctaneWorker;

    protected Product $kettle;

    protected Product $teapot;

    /** @var list<string> what was resolved before the first visitor — a worker's boot */
    protected array $booted = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->kettle = Product::create(['sku' => 'PC-KETTLE', 'name' => ['en' => 'Kept kettle'], 'slug' => 'kept-kettle', 'price' => 25_00, 'stock' => 10, 'status' => 'active']);
        $this->teapot = Product::create(['sku' => 'PC-TEAPOT', 'name' => ['en' => 'Other teapot'], 'slug' => 'other-teapot', 'price' => 15_00, 'stock' => 10, 'status' => 'active']);

        app(SettingsManager::class)->set('core', PageCache::ENABLED, true);

        $this->booted = $this->resolved();
    }

    /** @return list<string> */
    protected function resolved(): array
    {
        return array_keys((fn () => $this->instances)->call($this->app));
    }

    /**
     * A guest nobody has seen, as a worker serves them: no session, and
     * nothing resolved for the last visitor (Octane drops the request's
     * clone of the application; a test keeps one application throughout).
     */
    protected function newVisitor(): void
    {
        foreach ($this->resolved() as $abstract) {
            if (! in_array($abstract, $this->booted, true)) {
                $this->app->forgetInstance($abstract);
            }
        }

        $this->flushSession();
    }

    /** What happened — without the reason APP_DEBUG adds after a semicolon. */
    protected function cache(TestResponse $response): ?string
    {
        $state = $response->headers->get('X-Torgash-Cache');

        return $state === null ? null : strtok($state, ';');
    }

    protected function url(Product $product): string
    {
        return 'http://localhost/product/'.$product->slug;
    }

    /** The product page lists no other product but what this visitor viewed. */
    protected function onlyViewedRecentlyBelowProducts(): void
    {
        DB::table('m_blocks')->where('zone', 'catalog.product.bottom')
            ->whereRaw("coalesce(settings->>'source', '') <> 'viewed'")
            ->update(['enabled' => false]);
    }

    protected function assertKept(string $url): void
    {
        $this->newVisitor();
        $first = $this->get($url);
        $first->assertOk();
        $this->assertSame('stored', $this->cache($first), $url.' — '.$first->headers->get('X-Torgash-Cache'));

        $this->newVisitor();
        $second = $this->get($url);
        $second->assertOk();
        $this->assertSame('hit', $this->cache($second), $url.' — '.$second->headers->get('X-Torgash-Cache'));
    }

    public function test_the_home_page_the_catalogue_a_category_and_a_product_are_kept(): void
    {
        $category = Category::create(['name' => ['en' => 'Kettles'], 'slug' => 'kettles']);
        $this->kettle->categories()->attach($category->id);

        $this->assertKept('http://localhost/');
        $this->assertKept('http://localhost/catalog');
        $this->assertKept('http://localhost/catalog/kettles');
        $this->assertKept($this->url($this->kettle));
        $this->assertKept('http://localhost/catalog/kettles?sort=price_asc&page=1');
    }

    public function test_a_guests_basket_is_theirs_alone(): void
    {
        $badge = fn (TestResponse $response) => preg_match('/id="cart-badge"[^>]*aria-label="[^"(]*\((\d+)\)"/s', $response->getContent(), $m) ? (int) $m[1] : 0;

        $this->get($this->url($this->kettle));

        $this->post('http://localhost/cart/add/kept-kettle', ['qty' => 2])->assertOk();
        $mine = $this->get($this->url($this->kettle));
        $this->assertSame('hit', $this->cache($mine));
        $this->assertSame(2, $badge($mine));

        $this->newVisitor();
        $theirs = $this->get($this->url($this->kettle));
        $this->assertSame('hit', $this->cache($theirs));
        $this->assertSame(0, $badge($theirs));
        $theirs->assertSee('id="cart-badge"', false);
    }

    public function test_a_guests_wishlist_is_theirs_alone(): void
    {
        $pressed = fn (TestResponse $response) => substr_count($response->getContent(), 'aria-pressed="true"');
        $counter = fn (TestResponse $response) => preg_match('/id="wishlist-counter"[^>]*aria-label="[^"(]*\((\d+)\)"/s', $response->getContent(), $m) ? (int) $m[1] : 0;

        $this->get($this->url($this->kettle));

        $this->post('http://localhost/wishlist/toggle/kept-kettle', [], ['HX-Request' => 'true'])->assertOk();
        $mine = $this->get($this->url($this->kettle));
        $this->assertSame('hit', $this->cache($mine));
        $this->assertGreaterThan(0, $pressed($mine), 'the heart is pressed for the guest who pressed it');
        $this->assertSame(1, $counter($mine));

        $this->newVisitor();
        $theirs = $this->get($this->url($this->kettle));
        $this->assertSame('hit', $this->cache($theirs));
        $this->assertSame(0, $pressed($theirs));
        $this->assertSame(0, $counter($theirs));
    }

    public function test_a_guests_town_is_theirs_alone(): void
    {
        Country::firstOrCreate(['code' => 'UA'], ['name' => ['en' => 'Ukraine']]);
        $region = State::create(['country' => 'UA', 'code' => '30', 'name' => ['en' => 'Kyiv region'], 'enabled' => true]);
        $kyiv = Place::create(['country' => 'UA', 'state_id' => $region->id, 'name' => ['en' => 'Kyivograd'],
            'population' => 3_000_000, 'latitude' => 50.45, 'longitude' => 30.52, 'source' => 'import']);

        $this->get($this->url($this->kettle));

        $this->post('http://localhost/location', ['place_id' => $kyiv->id])->assertRedirect();
        $this->get($this->url($this->kettle))->assertSee('Kyivograd');

        $this->newVisitor();
        $theirs = $this->get($this->url($this->kettle));
        $this->assertSame('hit', $this->cache($theirs));
        $theirs->assertDontSee('Kyivograd');
    }

    public function test_viewed_recently_is_each_guests_own_and_kept_as_drawn_for_the_same_trail(): void
    {
        $this->onlyViewedRecentlyBelowProducts();

        // A guest who looked at the teapot first takes the kettle's copy.
        $this->get($this->url($this->teapot));
        $first = $this->get($this->url($this->kettle));
        $this->assertSame('stored', $this->cache($first));
        $first->assertSee('Other teapot');

        // A guest on their first page: the kettle's page lists nothing of anybody's.
        $this->newVisitor();
        $fresh = $this->get($this->url($this->kettle));
        $this->assertSame('hit', $this->cache($fresh));
        $fresh->assertDontSee('Other teapot');

        // The same trail as the first guest: the same list.
        $this->newVisitor();
        $this->get($this->url($this->teapot));
        $same = $this->get($this->url($this->kettle));
        $this->assertSame('hit', $this->cache($same));
        $same->assertSee('Other teapot');
    }

    public function test_a_view_is_counted_for_every_guest_served_from_a_copy(): void
    {
        $views = fn () => (int) DB::table('m_catalog_products')->where('id', $this->kettle->id)->value('views_count');
        $before = $views();

        $this->newVisitor();
        $this->assertSame('stored', $this->cache($this->get($this->url($this->kettle))));
        $this->newVisitor();
        $this->assertSame('hit', $this->cache($this->get($this->url($this->kettle))));
        $this->assertSame('hit', $this->cache($this->get($this->url($this->kettle))), 'the same guest again');

        $this->assertSame($before + 2, $views(), 'once per guest, served from a copy or not');
    }

    public function test_a_sale_throws_away_the_pages_that_show_the_product_and_no_other(): void
    {
        $this->onlyViewedRecentlyBelowProducts();

        $this->newVisitor();
        $this->get($this->url($this->kettle));
        $this->newVisitor();
        $this->get($this->url($this->teapot));

        $this->kettle->refresh()->adjustStock(-1);

        $this->newVisitor();
        $this->assertSame('stored', $this->cache($this->get($this->url($this->kettle))), 'drawn again with its new stock');
        $this->newVisitor();
        $this->assertSame('hit', $this->cache($this->get($this->url($this->teapot))), 'the teapot\'s page does not show the kettle');
    }

    public function test_an_edit_in_the_admin_throws_every_page_away(): void
    {
        $this->newVisitor();
        $this->get($this->url($this->teapot));

        $this->kettle->refresh()->update(['price' => 30_00]);

        $this->newVisitor();
        $this->assertSame('stored', $this->cache($this->get($this->url($this->teapot))));
    }

    public function test_two_guests_in_a_row_on_one_worker_each_get_their_own_page(): void
    {
        // Switched on in setUp(): the worker reads the setting from the
        // test's own transaction, as it reads a change made elsewhere.
        [$first, $second] = $this->serveInWorker([
            $this->workerGet($this->url($this->kettle)),
            $this->workerGet($this->url($this->kettle)),
        ]);

        $this->assertSame('stored', strtok((string) $first->headers->get('X-Torgash-Cache'), ';'));
        $this->assertSame('hit', strtok((string) $second->headers->get('X-Torgash-Cache'), ';'));

        preg_match('/name="csrf-token" content="([^"]+)"/', (string) $first->getContent(), $firstToken);
        preg_match('/name="csrf-token" content="([^"]+)"/', (string) $second->getContent(), $secondToken);
        $this->assertNotEmpty($firstToken[1] ?? null);
        $this->assertNotSame($firstToken[1], $secondToken[1] ?? null, 'each guest their own token');

        preg_match_all('/nonce="([0-9a-f]+)"/', (string) $second->getContent(), $nonces);
        $this->assertNotEmpty($nonces[1]);
        $this->assertSame([], array_values(array_diff(array_unique($nonces[1]), [$this->nonceIn($second)])), 'every script carries the nonce its policy allows');
        $this->assertSame('', $this->placeholdersIn((string) $second->getContent()), 'nothing of the copy is left for the visitor');
    }

    protected function nonceIn(\Symfony\Component\HttpFoundation\Response $response): string
    {
        $policy = $response->headers->get('Content-Security-Policy') ?? $response->headers->get('Content-Security-Policy-Report-Only') ?? '';

        return preg_match("/'nonce-([0-9a-f]+)'/", $policy, $m) ? $m[1] : '';
    }

    /** A copy's leftovers — `pc-<16 hex>-token` and the like — joined, or ''. */
    protected function placeholdersIn(string $html): string
    {
        preg_match_all('/pc-[0-9a-f]{16}-[a-z0-9]+/', $html, $m);

        return implode(' ', $m[0]);
    }
}
