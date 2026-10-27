<?php

namespace Tests\Feature\Catalog;

use App\Models\CustomerGroup;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Seo\ProductSchema;

/**
 * The product page as text, and the structured data in the page's head.
 *
 * Both are the same page read by a machine, which is why they are tested
 * together: they come from one object (`ProductFacts`) precisely so that they
 * cannot say different things, and the way to prove that is to ask both about
 * a product whose price is not its `price` column.
 */
class ProductTextTest extends TestCase
{
    public function test_the_text_version_carries_what_the_page_carries(): void
    {
        $this->product([
            'sku' => 'TXT-1',
            'name' => ['en' => 'Laptop Pro 14'],
            'slug' => 'laptop-text',
            'description' => ['en' => '<p>A <strong>fast</strong> laptop.</p>'],
            'features' => ['ram_gb' => 16],
            'price' => 1000_00,
            'stock' => 3,
        ]);

        $response = $this->get('http://localhost/product/laptop-text.md')->assertOk();

        // text/plain even though it IS Markdown: the address already says .md,
        // and the honest label makes a browser download the file instead of
        // showing it to the shop owner checking their own page.
        $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');

        $body = $response->getContent();

        $this->assertStringContainsString('# Laptop Pro 14', $body);
        $this->assertStringContainsString('1000.00 USD', $body);
        $this->assertStringContainsString('In stock', $body);
        $this->assertStringContainsString('TXT-1', $body);
        $this->assertStringContainsString('A **fast** laptop.', $body);
        $this->assertStringContainsString('| ram_gb | 16 |', $body);
    }

    /**
     * The whole point of one source of facts: a crawler has no account, so it
     * gets the price anybody would be quoted — not the one belonging to
     * whoever is logged in, and not the raw column when the product is on sale.
     */
    public function test_neither_rendition_quotes_a_price_belonging_to_the_reader(): void
    {
        $wholesale = CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 50]);
        $customer = User::create([
            'name' => 'A customer', 'email' => 'reader@example.test',
            'password' => bcrypt('secret'), 'customer_group_id' => $wholesale->id,
        ]);

        $product = $this->product([
            'sku' => 'TXT-2', 'name' => ['en' => 'On sale'], 'slug' => 'on-sale',
            'price' => 800_00, 'list_price' => 1000_00, 'stock' => 2,
        ]);

        $body = $this->actingAs($customer)->get('http://localhost/product/on-sale.md')->assertOk()->getContent();

        $this->assertStringContainsString('800.00 USD', $body);
        $this->assertStringContainsString('1000.00 USD', $body, 'the crossed-out price belongs in the text version too');
        $this->assertStringNotContainsString('400.00', $body, "the reader's own group price leaked into a public page");

        // And the head of the HTML page says the same number — it used to say
        // the `price` column, so a product on sale advertised the wrong amount
        // to every search engine that read it.
        $this->assertSame('800.00', ProductSchema::for($product)['offers']['price']);
    }

    public function test_the_structured_data_names_the_brand_and_can_be_added_to(): void
    {
        $product = $this->product(['sku' => 'TXT-3', 'name' => ['en' => 'Branded'], 'slug' => 'branded', 'price' => 100_00]);

        $feature = Feature::create(['name' => ['en' => 'Make'], 'code' => 'make', 'purpose' => 'brand', 'input_type' => 'select']);
        $value = FeatureValue::create(['feature_id' => $feature->id, 'value' => ['en' => 'Asus'], 'slug' => 'asus']);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $feature->id, 'feature_value_id' => $value->id]);

        $schema = ProductSchema::for($product->fresh()->load('featureAssignments.feature', 'featureAssignments.featureValue'));

        $this->assertSame('Asus', $schema['brand']['name']);

        // A ratings module contributes without the catalogue learning its name.
        hook_listen('catalog.product.structured_data', fn (array $schema) => $schema + ['aggregateRating' => ['ratingValue' => '4.5']]);

        $this->assertSame('4.5', ProductSchema::for($product)['aggregateRating']['ratingValue']);
    }

    /**
     * The counter measures interest. A crawler walking the catalogue once a
     * night is not interest, and the "Hit" badge is driven by this number.
     */
    public function test_reading_the_text_version_is_not_a_page_view(): void
    {
        $product = $this->product(['sku' => 'TXT-4', 'name' => ['en' => 'Counted'], 'slug' => 'counted', 'price' => 100_00]);

        $this->get('http://localhost/product/counted.md')->assertOk();

        $this->assertSame(0, $product->fresh()->views_count);
    }

    /**
     * The suffix must not be read as part of the slug — which is exactly what
     * happens when the two routes are registered the other way round, and it
     * looks like a missing product rather than a routing mistake.
     */
    public function test_the_html_page_still_answers_its_own_address(): void
    {
        $this->product(['sku' => 'TXT-5', 'name' => ['en' => 'Both ways'], 'slug' => 'both-ways', 'price' => 100_00]);

        $this->get('http://localhost/product/both-ways')->assertOk()->assertSee('Both ways');
        $this->get('http://localhost/product/both-ways.md')->assertOk();
        $this->get('http://localhost/product/no-such-thing.md')->assertNotFound();
    }

    /** Hidden is "in no listing", not "unreachable" — for the text version as much as for the page. */
    public function test_a_hidden_product_has_a_text_version_and_a_disabled_one_does_not(): void
    {
        $this->product(['sku' => 'TXT-6', 'name' => ['en' => 'Hidden'], 'slug' => 'hidden-one', 'price' => 100_00, 'status' => 'hidden']);
        $this->product(['sku' => 'TXT-7', 'name' => ['en' => 'Off'], 'slug' => 'disabled-one', 'price' => 100_00, 'status' => 'disabled']);

        $this->get('http://localhost/product/hidden-one.md')->assertOk();
        $this->get('http://localhost/product/disabled-one.md')->assertNotFound();
    }

    /**
     * A renamed product answers about its HTML address and the suffix goes
     * back on — one table of renames, not two.
     */
    public function test_a_renamed_product_redirects_the_text_address_too(): void
    {
        $product = $this->product(['sku' => 'TXT-8', 'name' => ['en' => 'Renamed'], 'slug' => 'old-slug', 'price' => 100_00]);

        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->patch('http://localhost/admin/catalog/products/'.$product->id, [
            'sku' => 'TXT-8', 'name' => 'Renamed', 'slug' => 'new-slug',
            'price' => '100', 'stock' => '0', 'status' => 'active',
        ])->assertRedirect();

        $this->assertSame('new-slug', $product->fresh()->slug);
        $this->get('http://localhost/product/old-slug.md')->assertRedirect('/product/new-slug.md');
    }

    protected function product(array $attributes): Product
    {
        return Product::create([
            'price' => 100_00,
            'stock' => 5,
            'status' => 'active',
            ...$attributes,
        ]);
    }
}
