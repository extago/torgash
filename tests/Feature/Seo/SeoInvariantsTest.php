<?php

namespace Tests\Feature\Seo;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Torgash\Catalog\Invariants\CatalogInvariants;
use Torgash\Catalog\Models\Product;
use Torgash\Seo\Invariants\SeoInvariants;

/**
 * The statements that make SEO checkable rather than a matter of opinion.
 *
 * Split the way every invariant in this engine is split — by who owns the data.
 * The catalogue says what must be true of a product (its title, its text, its
 * photograph); `torgash.seo` says what must be true of an ADDRESS, which is not
 * any one entity's property and is the one thing the catalogue could not answer
 * for the pages module as well.
 */
class SeoInvariantsTest extends TestCase
{
    protected function product(array $attributes = []): Product
    {
        return Product::create($attributes + [
            'sku' => 'INV-'.random_int(10000, 99999),
            'name' => ['en' => 'Invariant widget'],
            'slug' => 'invariant-widget-'.random_int(10000, 99999),
            'description' => ['en' => '<p>Text.</p>'],
            'price' => 10_00,
            'stock' => 1,
            'status' => 'active',
        ]);
    }

    protected function findings(string $key, int $limit = 50): array
    {
        $all = (new CatalogInvariants)->register() + app(SeoInvariants::class)->register();

        return collect(($all[$key]['check'])($limit))->all();
    }

    /** Two pages with one title compete with each other, and nobody is told. */
    public function test_two_products_sharing_a_title_are_found(): void
    {
        $this->product(['name' => ['en' => 'T-shirt']]);
        $this->product(['name' => ['en' => 'T-shirt']]);

        $found = collect($this->findings('catalog.titles_are_distinct'))->firstWhere('title', 'T-shirt');

        $this->assertNotNull($found, 'the duplicate was not reported');

        // One finding for the collision, not one per product: the fault is that
        // they are the same, not either of them.
        $this->assertSame(1, collect($this->findings('catalog.titles_are_distinct'))->where('title', 'T-shirt')->count());
    }

    /** A meta title of its own is what the page really prints, so that is what is compared. */
    public function test_a_meta_title_is_what_gets_compared(): void
    {
        $this->product(['name' => ['en' => 'Mug'], 'meta_title' => ['en' => 'Blue mug']]);
        $this->product(['name' => ['en' => 'Mug'], 'meta_title' => ['en' => 'Red mug']]);

        $titles = collect($this->findings('catalog.titles_are_distinct'))->pluck('title');

        $this->assertNotContains('Mug', $titles);
    }

    public function test_a_product_with_nothing_to_say_is_found(): void
    {
        $silent = $this->product(['description' => ['en' => '']]);

        $found = collect($this->findings('catalog.pages_say_something'))->pluck('id');

        $this->assertContains((string) $silent->id, $found);

        // ...and one with a description of its own is not, even with no meta
        // description: the page has a sentence to offer either way.
        $this->assertNotContains((string) $this->product()->id, collect($this->findings('catalog.pages_say_something'))->pluck('id'));
    }

    public function test_a_product_with_no_photograph_is_found(): void
    {
        // A picture by external link counts as a photograph only while the
        // shop shows such links (catalog setting `external_images`).
        app(\App\Core\Settings\SettingsManager::class)->set('torgash.catalog', 'external_images', true);

        $bare = $this->product();
        $pictured = $this->product(['image_url' => '/assets/img/thing.png']);

        $found = collect($this->findings('catalog.products_have_a_photograph'))->pluck('id');

        $this->assertContains((string) $bare->id, $found);
        $this->assertNotContains((string) $pictured->id, $found);

        // Switched off, the link shows nothing — so the product has no photograph.
        app(\App\Core\Settings\SettingsManager::class)->set('torgash.catalog', 'external_images', false);
        $this->assertContains((string) $pictured->id, collect($this->findings('catalog.products_have_a_photograph'))->pluck('id'));
    }

    /**
     * The addresses a broken canonical would be asked about, without actually
     * asking: the check fetches them, so the test pins the LIST instead — the
     * sitemap's own rows plus every canonical typed on a record, and nothing
     * belonging to somebody else's site.
     */
    public function test_a_canonical_is_among_the_addresses_that_get_asked(): void
    {
        // No real requests: the shop would otherwise fetch its own sitemap off
        // whatever host APP_URL names, which in a test is the dev site.
        Http::fake(['*' => Http::response('<urlset></urlset>')]);

        // ...and one request first, so the shop builds its own links against
        // this host rather than that one.
        $this->get('http://localhost/catalog')->assertOk();

        $ours = $this->product(['canonical_url' => 'http://localhost/no-such-route-at-all/x']);
        $this->product(['canonical_url' => 'https://example.test/their-page']);

        $addresses = array_keys($this->addresses());

        $this->assertContains('http://localhost/no-such-route-at-all/x', $addresses);
        $this->assertNotContains('https://example.test/their-page', $addresses);
        $this->assertSame(
            route('admin.catalog.products.edit', $ours->id),
            $this->addresses()['http://localhost/no-such-route-at-all/x'],
        );
    }

    /** @return array<string, string> */
    protected function addresses(): array
    {
        $invariants = app(SeoInvariants::class);
        $method = new \ReflectionMethod($invariants, 'addresses');

        return $method->invoke($invariants, 10);
    }

    /** Asking the shop for its own pages over HTTP is never done unasked. */
    public function test_the_address_self_test_is_manual(): void
    {
        $declared = app(SeoInvariants::class)->register();

        $this->assertTrue($declared['seo.published_addresses_answer']['manual']);

        // ...and the catalogue's three, which read nothing but the database,
        // are ordinary nightly ones.
        foreach ((new CatalogInvariants)->register() as $statement) {
            $this->assertArrayNotHasKey('manual', $statement);
        }
    }
}
