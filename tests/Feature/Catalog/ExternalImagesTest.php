<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * Pictures by external link: several, comma separated, behind a catalogue
 * setting that is off by default — and switching it off neither shows nor
 * erases what was stored.
 */
class ExternalImagesTest extends TestCase
{
    protected function product(string $urls): Product
    {
        return Product::create([
            'sku' => 'EXT-'.random_int(1000, 9999), 'name' => ['en' => 'Linked'], 'slug' => 'linked-'.random_int(1000, 9999),
            'price' => 10_00, 'stock' => 5, 'status' => 'active', 'image_url' => $urls,
        ]);
    }

    protected function setting(bool $on): void
    {
        app(SettingsManager::class)->set('torgash.catalog', 'external_images', $on);
    }

    public function test_only_https_and_own_paths_are_read_from_a_comma_list(): void
    {
        $this->assertSame(
            ['https://a.test/1.jpg', 'https://a.test/2.jpg', '/assets/x.png'],
            Product::parseImageUrls("https://a.test/1.jpg, http://a.test/insecure.jpg\nhttps://a.test/2.jpg,,//evil.test/x.jpg /assets/x.png https://a.test/1.jpg"),
        );
    }

    public function test_links_are_shown_only_while_the_setting_is_on(): void
    {
        $product = $this->product('https://a.test/1.jpg, https://a.test/2.jpg');

        $this->setting(false);
        $this->assertNull($product->primaryImageUrl(400));
        $this->assertSame([], $product->externalImageUrls());

        $this->setting(true);
        $this->assertSame('https://a.test/1.jpg', $product->primaryImageUrl(400));

        $this->get('http://localhost/product/'.$product->slug)
            ->assertOk()
            ->assertSee('https://a.test/1.jpg', false)
            ->assertSee('https://a.test/2.jpg', false);
    }

    public function test_a_hidden_field_does_not_erase_the_stored_links_and_a_shown_one_normalises_them(): void
    {
        $product = $this->product('https://a.test/1.jpg');
        $admin = User::role('admin')->firstOrFail();
        $form = ['sku' => $product->sku, 'name' => 'Linked', 'price' => '10', 'stock' => '5', 'status' => 'active'];

        // Switched off: the form carries no field, the links stay.
        $this->setting(false);
        $this->actingAs($admin)->put("http://localhost/admin/catalog/products/{$product->id}", $form)->assertSessionHasNoErrors();
        $this->assertSame('https://a.test/1.jpg', $product->fresh()->image_url);

        // Switched on: what is typed is kept as the list that will be read.
        $this->setting(true);
        $this->put("http://localhost/admin/catalog/products/{$product->id}", $form + ['image_url' => " https://a.test/2.jpg\nhttp://no.test/x.jpg, https://a.test/3.jpg "])
            ->assertSessionHasNoErrors();
        $this->assertSame('https://a.test/2.jpg, https://a.test/3.jpg', $product->fresh()->image_url);
    }

    public function test_the_form_shows_the_field_with_its_warning_only_when_allowed(): void
    {
        $product = $this->product('');
        $admin = User::role('admin')->firstOrFail();

        $this->setting(false);
        $this->actingAs($admin)->get("http://localhost/admin/catalog/products/{$product->id}/edit")
            ->assertOk()->assertDontSee('name="image_url"', false);

        $this->setting(true);
        $this->get("http://localhost/admin/catalog/products/{$product->id}/edit")
            ->assertOk()->assertSee('name="image_url"', false)->assertSee('alert-warning', false);
    }
}
