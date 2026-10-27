<?php

namespace Tests\Feature\Motivation;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Motivation\ContentRenderer;
use Torgash\Motivation\Models\MotivationItem;

/**
 * The motivation column's lists of payment and delivery methods show each
 * method's own logo once any has one — every row in the same slot, a method
 * without one standing in with an icon — and stay plain dashes otherwise.
 */
class MethodLogosTest extends TestCase
{
    public function test_logos_take_a_slot_on_every_row_once_any_method_has_one(): void
    {
        Storage::fake('public');
        PaymentMethod::query()->update(['enabled' => false]);
        $card = PaymentMethod::create(['name' => ['en' => 'Card'], 'position' => 1, 'enabled' => true]);
        PaymentMethod::create(['name' => ['en' => 'Cash'], 'position' => 2, 'enabled' => true]);

        $item = new MotivationItem(['content_type' => 'payment_methods']);
        $product = Product::create(['sku' => 'ML-1', 'name' => ['en' => 'Mug'], 'slug' => 'ml-mug', 'price' => 10_00, 'stock' => 1, 'status' => 'active']);
        $render = fn () => app(ContentRenderer::class)->render($item, $product);

        $plain = $render();
        $this->assertStringContainsString('— Card', $plain);
        $this->assertStringNotContainsString('torgash-method-logo', $plain);

        $file = tempnam(sys_get_temp_dir(), 'logo').'.png';
        $image = imagecreatetruecolor(40, 20);
        imagepng($image, $file);
        $card->addMedia($file)->toMediaCollection('logo');

        $withLogo = $render();
        $this->assertSame(2, substr_count($withLogo, 'torgash-method-logo'), 'Cash keeps the slot too');
        $this->assertStringContainsString($card->fresh()->logoUrl(), $withLogo);
        $this->assertStringNotContainsString('— Card', $withLogo);
    }
}
