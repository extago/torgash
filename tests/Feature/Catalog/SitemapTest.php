<?php

namespace Tests\Feature\Catalog;

use Tests\TestCase;
use Torgash\Catalog\Http\SitemapController;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;

/**
 * The list of addresses the shop invites crawlers to.
 *
 * Three properties, each of which was missing and each of which is invisible
 * when wrong: a two-language shop must say which addresses are translations of
 * each other (or they are read as near-duplicates competing with one another),
 * a photograph has no other way of being connected to its page, and past 50 000
 * addresses a single file is simply not read to the end.
 */
class SitemapTest extends TestCase
{
    public function test_it_is_valid_xml_and_names_the_products(): void
    {
        $product = Product::create([
            'sku' => 'MAP-1', 'name' => ['en' => 'Mapped'], 'slug' => 'mapped',
            'price' => 10_00, 'stock' => 1, 'status' => 'active',
        ]);

        $body = $this->get('http://localhost/sitemap.xml')->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->getContent();

        $xml = simplexml_load_string($body);

        $this->assertNotFalse($xml, 'the sitemap is not valid XML');
        $this->assertStringContainsString('http://localhost/product/'.$product->slug, $body);
    }

    /** The same links the page's own head carries, from the same place. */
    public function test_every_address_names_its_translations(): void
    {
        $body = $this->get('http://localhost/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<xhtml:link rel="alternate" hreflang="ru" href="http://localhost/ru/catalog"/>',
            $body,
        );
        $this->assertStringContainsString('xmlns:xhtml=', $body);
    }

    /** The home page and the brands that have something to show (audit 2026-10-15). */
    public function test_it_names_the_home_page_and_brands_with_products(): void
    {
        $make = Feature::create(['name' => ['en' => 'Make'], 'code' => 'map_make', 'purpose' => 'brand', 'input_type' => 'select']);
        $sold = FeatureValue::create(['feature_id' => $make->id, 'value' => ['en' => 'Mapped Make'], 'slug' => 'mapped-make']);
        FeatureValue::create(['feature_id' => $make->id, 'value' => ['en' => 'Empty Make'], 'slug' => 'empty-make']);
        $product = Product::create(['sku' => 'MAP-B', 'name' => ['en' => 'Branded'], 'slug' => 'branded', 'price' => 10_00, 'stock' => 1, 'status' => 'active']);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $make->id, 'feature_value_id' => $sold->id]);

        $body = $this->get('http://localhost/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>http://localhost/</loc>', $body, 'spelled as its canonical');
        $this->assertStringContainsString('<loc>http://localhost/brand/mapped-make</loc>', $body);
        $this->assertStringContainsString('<loc>http://localhost/brands</loc>', $body);
        $this->assertStringNotContainsString('empty-make', $body, 'a brand page with nothing on it is not advertised');
    }

    /** A hidden product has no business being advertised to a crawler. */
    public function test_a_hidden_product_is_not_in_it(): void
    {
        Product::create([
            'sku' => 'MAP-2', 'name' => ['en' => 'Hidden'], 'slug' => 'hidden-from-map',
            'price' => 10_00, 'stock' => 1, 'status' => 'hidden',
        ]);

        $this->get('http://localhost/sitemap.xml')->assertOk()
            ->assertDontSee('hidden-from-map', false);
    }

    /**
     * Past the format's limit the file becomes an index. Asserted on the
     * boundary rather than by making 50 000 products: what is worth pinning is
     * that both shapes are valid and that the pages are reachable.
     */
    public function test_the_paged_form_answers(): void
    {
        $body = $this->get('http://localhost/sitemap-1.xml')->assertOk()->getContent();

        $this->assertNotFalse(simplexml_load_string($body));
        $this->assertStringContainsString('<urlset', $body);

        // Past the end there is nothing, and saying so is better than an empty
        // file a crawler would keep coming back to.
        $this->get('http://localhost/sitemap-99.xml')->assertNotFound();
    }

    public function test_the_index_names_one_file_per_page(): void
    {
        // Rendered after a request, so the url generator has a root: outside
        // one it falls back to APP_URL, which is another host entirely.
        $this->get('http://localhost/sitemap.xml')->assertOk();

        $xml = view('torgash.catalog::sitemap-index', ['pages' => 3])->render();
        $parsed = simplexml_load_string($xml);

        $this->assertNotFalse($parsed);
        $this->assertCount(3, $parsed->sitemap);
        $this->assertSame('http://localhost/sitemap-2.xml', (string) $parsed->sitemap[1]->loc);
    }

    /** The limit is the format's, not ours — stated once, where the cut is made. */
    public function test_the_limit_is_the_formats_own(): void
    {
        $this->assertSame(50000, SitemapController::PER_FILE);
    }
}
