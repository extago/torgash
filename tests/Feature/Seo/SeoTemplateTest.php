<?php

namespace Tests\Feature\Seo;

use App\Models\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Media\Gallery;
use Torgash\Seo\Models\Template;
use Torgash\Seo\Templates;

/**
 * A sentence written once and applied to a thousand pages — and the rules about
 * who overrules whom, which is the whole of this module.
 *
 * The two that are easy to get wrong and impossible to notice: "only where
 * nothing is typed in" has to be measured against the RECORD (by the time a
 * template runs, the controller has already put the product's name in the
 * title, so asking the merged values would mean the template never fires), and
 * a scoped template has to beat the shop-wide one — including when what it says
 * is "do nothing here".
 */
class SeoTemplateTest extends TestCase
{
    protected Product $product;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => ['en' => 'Laptops'], 'slug' => 'laptops', 'enabled' => true]);

        $this->product = Product::create([
            'sku' => 'SEO-1',
            'name' => ['en' => 'Thin Laptop'],
            'slug' => 'thin-laptop',
            'price' => 100_00,
            'stock' => 2,
            'status' => 'active',
        ]);

        $this->product->categories()->attach($this->category->id);
    }

    protected function template(array $attributes = []): Template
    {
        app(Templates::class)->flush();

        return Template::create($attributes + [
            'subject' => 'product',
            'scope' => 'global',
            'mode' => Template::MODE_FILL_EMPTY,
            'enabled' => true,
        ]);
    }

    public function test_a_template_writes_the_title_of_a_product_that_has_none(): void
    {
        $this->template(['title' => ['en' => 'Buy {product} in {category|lower}']]);

        $this->get('http://localhost/product/thin-laptop')->assertOk()
            ->assertSee('<title>Buy Thin Laptop in laptops · ', false);
    }

    /** What somebody typed by hand survives — that is what "fill empty" means. */
    public function test_the_records_own_title_wins_over_the_template(): void
    {
        $this->template(['title' => ['en' => 'Buy {product}']]);
        $this->product->update(['meta_title' => ['en' => 'Handwritten']]);

        $this->get('http://localhost/product/thin-laptop')->assertOk()
            ->assertSee('<title>Handwritten · ', false);
    }

    public function test_override_replaces_even_a_handwritten_title(): void
    {
        $this->template(['title' => ['en' => 'Buy {product}'], 'mode' => Template::MODE_OVERRIDE]);
        $this->product->update(['meta_title' => ['en' => 'Handwritten']]);

        $this->get('http://localhost/product/thin-laptop')->assertOk()
            ->assertSee('<title>Buy Thin Laptop · ', false);
    }

    public function test_a_category_template_beats_the_shop_wide_one(): void
    {
        $this->template(['title' => ['en' => 'Everywhere']]);
        $this->template(['title' => ['en' => 'Laptops only'], 'scope' => 'category:'.$this->category->id]);

        $this->get('http://localhost/product/thin-laptop')->assertOk()
            ->assertSee('<title>Laptops only · ', false);
    }

    /** The reason "do nothing" is a mode and not just a disabled row. */
    public function test_a_category_can_opt_out_of_the_shop_wide_template(): void
    {
        $this->template(['title' => ['en' => 'Everywhere']]);
        $this->template(['scope' => 'category:'.$this->category->id, 'mode' => Template::MODE_SKIP]);

        $this->get('http://localhost/product/thin-laptop')->assertOk()
            ->assertSee('<title>Thin Laptop · ', false);
    }

    /** A rule written at the top of the tree governs everything under it. */
    public function test_a_parent_categorys_template_reaches_a_child(): void
    {
        $child = Category::create([
            'name' => ['en' => 'Gaming'], 'slug' => 'gaming', 'enabled' => true,
            'parent_id' => $this->category->id,
        ]);
        $this->product->categories()->sync([$child->id]);

        $this->template(['title' => ['en' => 'From the parent'], 'scope' => 'category:'.$this->category->id]);

        $this->get('http://localhost/product/thin-laptop')->assertOk()
            ->assertSee('<title>From the parent · ', false);
    }

    /**
     * A parent's page lists what stands in its children, so its prices and its
     * count are theirs. Reported from the live shop (2026-10-29): "Smartphones"
     * over its brands showed their phones and an empty {min_price}.
     */
    public function test_a_parent_categorys_prices_and_count_are_what_its_page_lists(): void
    {
        $gaming = Category::create(['name' => ['en' => 'Gaming'], 'slug' => 'gaming', 'enabled' => true, 'parent_id' => $this->category->id]);
        $office = Category::create(['name' => ['en' => 'Office'], 'slug' => 'office', 'enabled' => true, 'parent_id' => $this->category->id]);
        $this->product->categories()->sync([$gaming->id]);
        Product::create([
            'sku' => 'SEO-2', 'name' => ['en' => 'Desk Laptop'], 'slug' => 'desk-laptop',
            'price' => 250_00, 'stock' => 1, 'status' => 'active',
        ])->categories()->attach($office->id);

        $this->template([
            'subject' => 'category', 'mode' => Template::MODE_OVERRIDE,
            'title' => ['en' => '{category} from {min_price} to {max_price}, {total_products} of them'],
        ]);

        $this->get('http://localhost/catalog/laptops')->assertOk()
            ->assertSee('Laptops from '.format_price(100_00).' to '.format_price(250_00).', 2 of them');
    }

    /**
     * The square brackets: print the dash only when there is something after
     * it. Other engines solve this with brackets around the token; ours are free
     * because a token is braces.
     */
    public function test_an_optional_fragment_disappears_with_its_own_punctuation(): void
    {
        $this->template(['description' => ['en' => '{product}[ — {feature:colour}] in stock.']]);

        $this->get('http://localhost/product/thin-laptop')->assertOk()
            ->assertSee('<meta name="description" content="Thin Laptop in stock.">', false);

        $this->product->update(['features' => ['colour' => 'Silver']]);

        $this->get('http://localhost/product/thin-laptop')->assertOk()
            ->assertSee('<meta name="description" content="Thin Laptop — Silver in stock.">', false);
    }

    /** A template is text in an attribute, so it must not be escaped twice. */
    public function test_a_name_with_an_ampersand_is_escaped_once(): void
    {
        $this->product->update(['name' => ['en' => 'Nuts & Bolts']]);
        $this->template(['title' => ['en' => '{product}']]);

        $body = $this->get('http://localhost/product/thin-laptop')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Nuts &amp; Bolts · ', $body);
        $this->assertStringNotContainsString('&amp;amp;', $body);
    }

    /** An unknown token stays visible, so a typo is the author's to see. */
    public function test_an_unknown_token_is_left_as_written(): void
    {
        $this->template(['title' => ['en' => '{prodcut} here']]);

        $this->get('http://localhost/product/thin-laptop')->assertOk()
            ->assertSee('<title>{prodcut} here · ', false);
    }

    public function test_the_audit_screen_lists_what_is_empty_and_fills_it_in(): void
    {
        $this->template(['title' => ['en' => 'Buy {product}']]);

        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)
            ->get('http://localhost/admin/seo/audit?subject=product&filled=empty')
            ->assertOk()
            ->assertSee('Thin Laptop');

        $this->actingAs($admin)->post('http://localhost/admin/seo/audit', [
            'subject' => 'product',
            'action' => 'template',
            'selected' => [$this->product->id],
        ])->assertRedirect();

        // Written onto the RECORD, which is the point: it survives this module.
        $this->assertSame('Buy Thin Laptop', $this->product->fresh()->meta_title['en'] ?? null);
    }

    /** Typed text wins over the template on the same submit — a keystroke must not be lost. */
    public function test_what_was_typed_beats_what_the_template_would_have_written(): void
    {
        $this->template(['title' => ['en' => 'Buy {product}']]);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/seo/audit', [
                'subject' => 'product',
                'action' => 'template',
                'selected' => [$this->product->id],
                'rows' => [$this->product->id => ['title' => 'By hand']],
            ])->assertRedirect();

        $this->assertSame('By hand', $this->product->fresh()->meta_title['en'] ?? null);
    }

    /**
     * The screens themselves — cheap to assert and the only thing that catches
     * a broken view before somebody opens it.
     */
    public function test_the_admin_screens_render(): void
    {
        $template = $this->template(['title' => ['en' => 'Buy {product}']]);
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/seo')->assertOk()->assertSee('Buy {product}');
        $this->actingAs($admin)->get('http://localhost/admin/seo/create')->assertOk();
        $this->actingAs($admin)->get('http://localhost/admin/seo/'.$template->id.'/edit')->assertOk();

        // ...and the SEO card the catalogue and the pages module both draw,
        // which is core's component rendered inside three different forms.
        $this->actingAs($admin)->get('http://localhost/admin/catalog/products/'.$this->product->id.'/edit')
            ->assertOk()->assertSee('name="meta_description"', false);
        $this->actingAs($admin)->get('http://localhost/admin/catalog/categories/'.$this->category->id.'/edit')
            ->assertOk()->assertSee('name="canonical_url"', false);
    }

    /** A record's own noindex reaches the page it is about. */
    public function test_a_product_marked_noindex_says_so(): void
    {
        $this->get('http://localhost/product/thin-laptop')->assertOk()->assertDontSee('name="robots"', false);

        $this->product->update(['noindex' => true]);

        $this->get('http://localhost/product/thin-laptop')->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    /** ...and so does a canonical it was given by hand. */
    public function test_a_canonical_typed_on_the_record_wins(): void
    {
        $this->product->update(['canonical_url' => 'https://example.test/other']);

        $this->get('http://localhost/product/thin-laptop')->assertOk()
            ->assertSee('<link rel="canonical" href="https://example.test/other">', false);
    }

    /**
     * The alt text of a photograph nobody captioned — the commonest empty field
     * in a real catalogue, and the one written for a reader who cannot see the
     * page at all.
     */
    public function test_a_template_names_a_photograph_that_has_no_caption(): void
    {
        $this->template(['image_alt' => ['en' => '{product}[ {image_number}]']]);

        $media = $this->photo();

        $this->assertSame('Thin Laptop', Gallery::altFor($media, $this->product, 0));

        // The first photograph has no number; the rest opt in through the
        // optional fragment, which is what keeps "Laptop 1" out of the markup.
        $this->assertSame('Thin Laptop 2', Gallery::altFor($media, $this->product, 1));
    }

    /** ...and never over a caption somebody wrote. */
    public function test_a_typed_caption_survives_the_template(): void
    {
        $this->template(['image_alt' => ['en' => '{product}']]);

        $media = $this->photo();
        $media->setCustomProperty('alt', 'Typed by hand')->save();

        $this->assertSame('Typed by hand', Gallery::altFor($media, $this->product, 0));
    }

    protected function photo(): Media
    {
        $path = tempnam(sys_get_temp_dir(), 'seo').'.png';
        // A one-pixel PNG, because the media pipeline reads the bytes.
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        return $this->product->addMedia($path)->toMediaCollection('gallery');
    }

    /** Clearing a box removes that language rather than storing an empty string. */
    public function test_an_emptied_field_is_cleared_rather_than_blanked(): void
    {
        $this->product->update(['meta_title' => ['en' => 'Was here', 'ru' => 'Было']]);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/seo/audit', [
                'subject' => 'product',
                'action' => 'save',
                'rows' => [$this->product->id => ['title' => '']],
            ])->assertRedirect();

        $this->assertSame(['ru' => 'Было'], $this->product->fresh()->meta_title);
    }
}
