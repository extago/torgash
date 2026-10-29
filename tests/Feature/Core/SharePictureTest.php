<?php

namespace Tests\Feature\Core;

use App\Core\Seo\SharePicture;
use App\Models\Storefront;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Pages\Models\Page;

/**
 * The picture a link shows in a messenger or a social network (og:image):
 * the storefront's for its home page and every page with none of its own, a
 * record's own from the SEO card over everything else (the owner, 2026-10-29:
 * a link to the shop sent to Discord showed a bare title).
 */
class SharePictureTest extends TestCase
{
    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->storefront = Storefront::where('is_default', true)->firstOrFail();
        $this->actingAs(User::role('admin')->firstOrFail());
    }

    public function test_the_storefronts_picture_is_the_home_pages_and_every_page_without_one(): void
    {
        $this->assertStringNotContainsString('og:image', $this->get('http://localhost/')->getContent(), 'none yet');

        $this->put('http://localhost/admin/storefronts/'.$this->storefront->id, $this->storefrontForm([
            'share_file' => UploadedFile::fake()->image('shop.jpg', 1200, 630),
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $path = (string) $this->storefront->fresh()->share_image;
        $this->assertStringStartsWith(SharePicture::DIR.'/', $path);
        Storage::disk('public')->assertExists($path);

        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('<meta property="og:image" content="'.SharePicture::url($path).'">', $home);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $home);

        $this->page('delivery');
        $this->get('http://localhost/delivery')->assertOk()
            ->assertSee('<meta property="og:image" content="'.SharePicture::url($path).'">', false);
    }

    public function test_a_records_own_picture_wins_and_goes_when_removed(): void
    {
        $this->storefront->update(['share_image' => $shop = $this->stored()]);
        $page = $this->page('delivery');

        $this->put('http://localhost/admin/pages/'.$page->id, $this->pageForm([
            'share_file' => UploadedFile::fake()->image('delivery.png', 1200, 630),
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $own = (string) $page->fresh()->share_image;
        $this->assertStringStartsWith(SharePicture::DIR.'/', $own);
        $this->get('http://localhost/delivery')->assertOk()
            ->assertSee('<meta property="og:image" content="'.SharePicture::url($own).'">', false);

        // Saved again without touching it: kept.
        $this->put('http://localhost/admin/pages/'.$page->id, $this->pageForm())->assertRedirect();
        $this->assertSame($own, $page->fresh()->share_image);

        $this->put('http://localhost/admin/pages/'.$page->id, $this->pageForm(['share_image_remove' => '1']))->assertRedirect();
        $this->assertNull($page->fresh()->share_image);
        Storage::disk('public')->assertMissing($own);
        $this->get('http://localhost/delivery')->assertOk()
            ->assertSee('<meta property="og:image" content="'.SharePicture::url($shop).'">', false);
    }

    /** A product's photo is its picture until the SEO card says otherwise. */
    public function test_a_products_own_picture_is_put_over_its_photo(): void
    {
        $product = Product::create([
            'sku' => 'SP-1', 'name' => ['en' => 'Shared'], 'slug' => 'sp-1',
            'price' => 10_00, 'stock' => 1, 'status' => 'active',
            'share_image' => $own = $this->stored(),
        ]);

        $this->get('http://localhost/product/'.$product->slug)->assertOk()
            ->assertSee('<meta property="og:image" content="'.SharePicture::url($own).'">', false);
    }

    /** A duplicate gets a file of its own: removing the copy's picture must leave the original's. */
    public function test_a_duplicated_product_does_not_share_the_file(): void
    {
        $product = Product::create([
            'sku' => 'SP-2', 'name' => ['en' => 'Original'], 'slug' => 'sp-2',
            'price' => 10_00, 'stock' => 1, 'status' => 'active',
            'share_image' => $own = $this->stored(),
        ]);

        $this->post('http://localhost/admin/catalog/products/'.$product->id.'/duplicate')->assertRedirect();

        $copy = Product::where('id', '!=', $product->id)->where('sku', 'like', 'SP-2%')->firstOrFail();
        $this->assertNotNull($copy->share_image);
        $this->assertNotSame($own, $copy->share_image);
        Storage::disk('public')->assertExists($copy->share_image);

        SharePicture::forget($copy->share_image);
        Storage::disk('public')->assertExists($own);
    }

    public function test_an_svg_is_refused(): void
    {
        $page = $this->page('delivery');

        $this->put('http://localhost/admin/pages/'.$page->id, $this->pageForm([
            'share_file' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
        ]))->assertSessionHasErrors('share_file');

        $this->assertNull($page->fresh()->share_image);
    }

    public function test_the_forms_carry_the_field(): void
    {
        $this->get('http://localhost/admin/storefronts/'.$this->storefront->id.'/edit')->assertOk()
            ->assertSee('name="share_file"', false)
            ->assertSee('enctype="multipart/form-data"', false);

        $this->get('http://localhost/admin/pages/'.$this->page('delivery')->id.'/edit')->assertOk()
            ->assertSee('name="share_file"', false)
            ->assertSee('enctype="multipart/form-data"', false);
    }

    protected function stored(): string
    {
        $path = SharePicture::DIR.'/'.str_repeat('A', 40).random_int(10, 99).'.jpg';
        Storage::disk('public')->put($path, 'picture');

        return $path;
    }

    protected function page(string $slug): Page
    {
        return Page::create(['title' => ['en' => 'Delivery'], 'body' => ['en' => '<p>Body</p>'], 'slug' => $slug, 'enabled' => true]);
    }

    /** @return array<string, mixed> */
    protected function pageForm(array $extra = []): array
    {
        return ['title' => 'Delivery', 'slug' => 'delivery', 'enabled' => '1'] + $extra;
    }

    /** @return array<string, mixed> */
    protected function storefrontForm(array $extra = []): array
    {
        return [
            'code' => $this->storefront->code,
            'name' => $this->storefront->name,
            'domains' => implode(', ', $this->storefront->domains ?? []),
            'theme' => $this->storefront->theme ?: 'default',
            'default_locale' => $this->storefront->default_locale,
            'locales' => $this->storefront->locales,
            'default_currency' => $this->storefront->default_currency,
            'currencies' => $this->storefront->currencies,
        ] + $extra;
    }
}
