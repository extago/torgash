<?php

namespace Tests\Feature\Catalog;

use App\Core\Themes\ThemeSettings;
use App\Models\Storefront;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;

/**
 * The pictures around a product: what stands where it has none (a theme's
 * image setting), a category's own icon picture, and the gallery with its
 * full-screen viewer.
 */
class ProductPicturesTest extends TestCase
{
    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    protected function product(string $sku, int $photos = 0): Product
    {
        $product = Product::create(['sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku), 'price' => 100_00, 'stock' => 5, 'status' => 'active']);

        for ($i = 0; $i < $photos; $i++) {
            $path = tempnam(sys_get_temp_dir(), 'ppt').'.png';
            file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
            $product->addMedia($path)->toMediaCollection('gallery');
        }

        return $product;
    }

    /** No photo is one component everywhere — never the grey letter each template used to invent. */
    public function test_a_product_with_no_photo_draws_the_placeholder(): void
    {
        $product = $this->product('PPT-NONE');

        $page = $this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent();

        $this->assertStringContainsString('torgash-no-image', $page);
        $this->assertStringNotContainsString('opacity-10 font-black">T<', $page);
        $this->assertNull($product->primaryImageUrl(), 'a feed never gets a placeholder');
    }

    /**
     * The theme's image setting: uploaded on its own and carried as a PATH we
     * wrote — a path a request invents is refused rather than printed.
     */
    public function test_the_shops_own_placeholder_is_a_theme_image(): void
    {
        Storage::fake('public');
        $storefront = Storefront::query()->orderBy('id')->firstOrFail();
        $settings = app(ThemeSettings::class);

        $answer = $this->actingAs($this->admin())->post(route('admin.themes.image', $storefront), [
            'key' => 'no_image',
            'file' => UploadedFile::fake()->image('ours.png', 200, 200),
        ])->assertOk()->json();

        $this->assertMatchesRegularExpression('#^theme-images/\d+/no_image-[A-Za-z0-9]{16}\.png$#', $answer['path']);
        $this->assertSame(['no_image' => $answer['path']], $settings->clean(['no_image' => $answer['path']]));
        $this->assertSame([], $settings->clean(['no_image' => '../../.env']), 'a path we did not write');
        $this->assertSame([], $settings->clean(['no_image' => 'theme-images/1/no_image-AAAAAAAAAAAAAAAA.png']), 'one that is not there');

        $settings->save($storefront, ['no_image' => $answer['path']]);
        $product = $this->product('PPT-OWN');

        $this->get('http://localhost/product/'.$product->slug)->assertOk()->assertSee($answer['url'], false);
    }

    public function test_an_svg_or_a_non_image_field_is_refused(): void
    {
        $storefront = Storefront::query()->orderBy('id')->firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.themes.image', $storefront), [
            'key' => 'no_image',
            'file' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->actingAs($admin)->post(route('admin.themes.image', $storefront), [
            'key' => 'primary',
            'file' => UploadedFile::fake()->image('a.png'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    /** A category's own picture wins over its Lucide name, and a new upload replaces the old file. */
    public function test_a_category_can_have_its_own_icon_picture(): void
    {
        Storage::fake('public');
        $category = Category::create(['name' => ['en' => 'Tackle'], 'slug' => 'ppt-tackle', 'icon' => 'fish', 'enabled' => true]);
        $admin = $this->actingAs($this->admin());
        $form = ['name' => 'Tackle', 'slug' => 'ppt-tackle', 'icon' => 'fish', 'enabled' => 1];

        $admin->put(route('admin.catalog.categories.update', $category), $form + ['icon_file' => UploadedFile::fake()->image('a.png', 64, 64)])->assertRedirect();
        $first = $category->fresh()->icon_image;
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);

        $admin->put(route('admin.catalog.categories.update', $category), $form + ['icon_file' => UploadedFile::fake()->image('b.png', 64, 64)])->assertRedirect();
        $second = $category->fresh()->icon_image;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);

        $this->get('http://localhost/')->assertOk()->assertSee(Storage::disk('public')->url($second), false);

        $admin->put(route('admin.catalog.categories.update', $category), $form + ['icon_image_remove' => 1])->assertRedirect();
        $this->assertNull($category->fresh()->icon_image);
        Storage::disk('public')->assertMissing($second);
    }

    /** The gallery: a strip that scrolls, the three sizes, the viewer's real dimensions and its page-drawn icons. */
    public function test_the_gallery_hands_the_viewer_what_it_needs(): void
    {
        $product = $this->product('PPT-GAL', 3);

        $page = $this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent();

        $this->assertStringContainsString('torgashGallery(', $page);
        $this->assertStringContainsString('snap-x snap-mandatory', $page, 'a thumb swipes it with no script');
        $this->assertStringContainsString('1/3', $page);
        $this->assertStringContainsString('data-gallery-icon="arrow-left"', $page);
        $this->assertMatchesRegularExpression('/\\\\u0022width\\\\u0022:1\b|"width":1\b/', $page, 'the original\'s size, so the viewer measures nothing');
    }
}
