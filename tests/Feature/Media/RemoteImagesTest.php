<?php

namespace Tests\Feature\Media;

use App\Core\Http\OutboundUrl;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Media\Http\Admin\GalleryController;

/**
 * A pasted link makes the shop's own server do the work, inside one request:
 * how many links, and how much of each, is the shop's to say.
 */
class RemoteImagesTest extends TestCase
{
    public function test_one_request_fetches_a_limited_number_of_links(): void
    {
        Http::fake(['*' => Http::response('not an image')]);

        $product = Product::create(['sku' => 'URL-1', 'name' => ['en' => 'Linked'], 'slug' => 'linked-1', 'price' => 100, 'stock' => 1, 'status' => 'active']);
        $links = implode("\n", array_map(fn (int $i) => 'https://cdn.example.test/'.$i.'.jpg', range(1, GalleryController::MAX_LINKS + 15)));

        $this->actingAs(User::role('admin')->firstOrFail())
            ->postJson('http://localhost/admin/media/product/'.$product->id.'/from-url', ['urls' => $links])
            ->assertStatus(422)
            ->assertJsonFragment(['Only the first '.GalleryController::MAX_LINKS.' links were taken. Add the rest in the next go.']);

        Http::assertSentCount(GalleryController::MAX_LINKS);
    }

    public function test_a_body_over_the_limit_is_refused(): void
    {
        Http::fake(['*' => Http::response(str_repeat('x', 2048))]);

        $this->expectException(RuntimeException::class);

        app(OutboundUrl::class)->get('https://cdn.example.test/big.jpg', maxBytes: 1024);
    }

    public function test_a_body_within_the_limit_passes(): void
    {
        Http::fake(['*' => Http::response(str_repeat('x', 1024))]);

        $this->assertSame(1024, strlen(app(OutboundUrl::class)->get('https://cdn.example.test/ok.jpg', maxBytes: 1024)->body()));
    }
}
