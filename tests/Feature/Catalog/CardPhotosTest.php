<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * A card flips through the product's photos under the pointer (the owner's
 * reference: tac-defense's catalogue) — one strip of the figure per photo,
 * a mark per photo under it, bounded by `card_photos_max`.
 */
class CardPhotosTest extends TestCase
{
    public function test_a_card_with_several_photos_carries_a_strip_and_a_mark_for_each(): void
    {
        $product = $this->product('CPH-1', 3);

        $html = $this->get('http://localhost/catalog')->assertOk()->getContent();
        $card = $this->card($html, $product);

        $this->assertSame(3, substr_count($card, 'data-card-photo-zone='));
        $this->assertStringContainsString('torgash-card-marks', $card);
        $this->assertStringContainsString('card-photos.js', $html, 'the script is on the page');
    }

    public function test_the_shop_bounds_how_many_and_can_switch_it_off(): void
    {
        $product = $this->product('CPH-2', 3);

        app(SettingsManager::class)->set('torgash.catalog', 'card_photos_max', 2);
        $card = $this->card($this->get('http://localhost/catalog')->getContent(), $product);
        $this->assertSame(2, substr_count($card, 'data-card-photo-zone='));

        app(SettingsManager::class)->set('torgash.catalog', 'card_photos_max', 1);
        $html = $this->get('http://localhost/catalog')->getContent();
        $this->assertStringNotContainsString('data-card-photo-zone=', $this->card($html, $product));
        $this->assertStringNotContainsString('card-photos.js', $html);
    }

    /** One photo is nothing to flip through, and says so by drawing no marks. */
    public function test_a_single_photo_draws_nothing_extra(): void
    {
        $product = $this->product('CPH-3', 1);

        $card = $this->card($this->get('http://localhost/catalog')->getContent(), $product);

        $this->assertStringNotContainsString('torgash-card-marks', $card);
    }

    protected function product(string $sku, int $photos): Product
    {
        $product = Product::create(['sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku), 'price' => 100_00, 'stock' => 5, 'status' => 'active', 'created_at' => now()->addYear()]);

        for ($i = 0; $i < $photos; $i++) {
            $path = tempnam(sys_get_temp_dir(), 'cph').'.png';
            // A one-pixel PNG, because the media pipeline reads the bytes.
            file_put_contents($path, base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
            ));
            $product->addMedia($path)->toMediaCollection('gallery');
        }

        return $product;
    }

    /** The one card, from its link to the next card's start. */
    protected function card(string $html, Product $product): string
    {
        $start = strpos($html, 'href="'.route('catalog.product', $product->slug).'"');
        $this->assertNotFalse($start, 'the card is on the first page');

        $end = strpos($html, 'torgash-card relative', $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }
}
