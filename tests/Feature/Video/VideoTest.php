<?php

namespace Tests\Feature\Video;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Seo\ProductSchema;
use Torgash\Video\Models\Video;
use Torgash\Video\Sources\Sources;
use Torgash\Video\Videos;

/**
 * Videos beside a product's photos (PLAN §50): what an address becomes, what
 * pasted code is allowed to become, and that the storefront asks the platform
 * for nothing until play is pressed.
 */
class VideoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsManager::class)->set('torgash.video', 'allowed_hosts', "rutube.ru\nok.ru");
        app(SettingsManager::class)->set('torgash.video', 'card_icon', true);

        // No test talks to a real platform. A title for YouTube, a picture
        // for its thumbnail address, silence from everybody else.
        Http::fake([
            'www.youtube.com/oembed*' => Http::response(['title' => 'Unboxing the camera']),
            'i.ytimg.com/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response('', 404),
        ]);
    }

    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function addresses(): iterable
    {
        yield 'youtube watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s', 'youtube', ['id' => 'dQw4w9WgXcQ']];
        yield 'youtube short link' => ['youtu.be/dQw4w9WgXcQ', 'youtube', ['id' => 'dQw4w9WgXcQ']];
        yield 'youtube shorts' => ['https://youtube.com/shorts/dQw4w9WgXcQ', 'youtube', ['id' => 'dQw4w9WgXcQ']];
        yield 'youtube embed code' => ['<iframe width="560" src="https://www.youtube.com/embed/dQw4w9WgXcQ?si=x" allowfullscreen></iframe>', 'youtube', ['id' => 'dQw4w9WgXcQ']];
        yield 'vimeo' => ['https://vimeo.com/76979871', 'vimeo', ['id' => '76979871']];
        yield 'vimeo unlisted' => ['https://vimeo.com/76979871/a1b2c3d4e5', 'vimeo', ['id' => '76979871', 'h' => 'a1b2c3d4e5']];
        yield 'vimeo player' => ['https://player.vimeo.com/video/76979871?h=a1b2c3d4e5', 'vimeo', ['id' => '76979871', 'h' => 'a1b2c3d4e5']];
        yield 'vk page' => ['https://vkvideo.ru/video-22822305_456242110', 'vk', ['oid' => '-22822305', 'id' => '456242110']];
        yield 'vk in a wall' => ['https://vk.com/club1?z=video-22822305_456242110%2Fpl', 'vk', ['oid' => '-22822305', 'id' => '456242110']];
        yield 'vk embed code' => ['<iframe src="https://vk.com/video_ext.php?oid=-22822305&amp;id=456242110&amp;hash=abc123" width="853"></iframe>', 'vk', ['oid' => '-22822305', 'id' => '456242110', 'hash' => 'abc123']];
        yield 'bilibili' => ['https://www.bilibili.com/video/BV1GJ411x7h7/?spm=1', 'bilibili', ['bvid' => 'BV1GJ411x7h7']];
        yield 'bilibili player' => ['//player.bilibili.com/player.html?aid=80433022&page=1', 'bilibili', ['aid' => '80433022']];
        yield 'an allowed site' => ['<iframe src="https://rutube.ru/play/embed/abc"></iframe>', 'embed', ['src' => 'https://rutube.ru/play/embed/abc']];
    }

    #[DataProvider('addresses')]
    public function test_an_address_becomes_a_platform_and_what_it_needs(string $typed, string $source, array $ref): void
    {
        $resolved = app(Sources::class)->resolve($typed);

        $this->assertSame($source, $resolved['source']->key());
        $this->assertSame($ref, $resolved['ref']);
    }

    /**
     * Pasted code is never stored or printed: a site nobody allowed is
     * refused, a plain http one too, and the shop's own host never — a
     * sandbox allowing scripts AND the same origin is no sandbox for it.
     */
    public function test_embed_code_from_a_site_nobody_allowed_is_refused(): void
    {
        foreach ([
            '<iframe src="https://evil.example/player"></iframe>',
            'https://rutube.ru.evil.example/x',
            'http://rutube.ru/play/embed/abc',
            '<script>alert(1)</script>',
            rtrim((string) config('app.url'), '/').'/player',
        ] as $typed) {
            try {
                app(Sources::class)->resolve($typed);
                $this->fail("Accepted: {$typed}");
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_the_admin_adds_a_video_and_the_platform_gives_its_title_and_poster(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->postJson("/admin/video/product/{$product->id}", ['video' => 'https://youtu.be/dQw4w9WgXcQ'])
            ->assertOk()
            ->assertJsonPath('errors', [])
            ->assertJsonPath('items.0.platform', 'YouTube')
            ->assertJsonPath('items.0.title', 'Unboxing the camera');

        $video = Video::query()->sole();
        $this->assertNotNull($video->poster(), 'the poster is kept by the shop');

        // The same video twice is one video.
        $this->postJson("/admin/video/product/{$product->id}", ['video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'])
            ->assertJsonPath('errors.0', __('This video has already been added.'));
        $this->assertSame(1, Video::query()->count());
    }

    /** A platform that says nothing still gets its video — and the admin is told to upload a poster. */
    public function test_a_silent_platform_still_gets_its_video(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->postJson("/admin/video/product/{$product->id}", ['video' => 'https://vkvideo.ru/video-1_2'])
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonCount(1, 'errors');

        $this->assertNull(Video::query()->sole()->poster());
    }

    public function test_titles_and_order_ride_along_with_the_product_form(): void
    {
        $product = $this->product();
        $other = $this->product('VID-2');
        $first = app(Videos::class)->add('product', $product->id, 'https://vimeo.com/111111');
        $second = app(Videos::class)->add('product', $product->id, 'https://vimeo.com/222222');
        $foreign = app(Videos::class)->add('product', $other->id, 'https://vimeo.com/333333');

        app(Videos::class)->applyForm('product', $product->id, [
            $first->id => ['title' => 'Close up', 'position' => 2],
            $second->id => ['title' => '', 'position' => 1],
            $foreign->id => ['title' => 'Not yours'],
        ]);

        $this->assertSame([$second->id, $first->id], app(Videos::class)->of('product', $product->id)->pluck('id')->all());
        $this->assertSame('Close up', $first->fresh()->t('title'));
        $this->assertNull($foreign->fresh()->title, 'another product\'s video is not this form\'s to change');
    }

    /**
     * The storefront shows the shop's own copy of the poster and a play
     * button; the platform's player sits inert in a <template> until play.
     */
    public function test_the_product_page_asks_the_platform_for_nothing_before_play(): void
    {
        $product = $this->product();
        app(Videos::class)->add('product', $product->id, 'https://youtu.be/dQw4w9WgXcQ');

        $html = $this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent();

        $this->assertStringContainsString('data-video-player', $html);
        $this->assertStringContainsString(__('The video loads from :platform', ['platform' => 'YouTube']), $html);
        $this->assertStringNotContainsString('i.ytimg.com', $html, 'the poster is the shop\'s copy');

        // No player outside the template. (The head's structured data names
        // its address as `embedUrl` — a fact for a search engine, not a load.)
        $outside = preg_replace('#<template data-video-player>.*?</template>#s', '', $html);
        $this->assertStringNotContainsString('<iframe', $outside);
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $html);
    }

    public function test_search_engines_are_told_about_a_video_with_a_poster(): void
    {
        $product = $this->product();
        app(Videos::class)->add('product', $product->id, 'https://youtu.be/dQw4w9WgXcQ');
        app(Videos::class)->add('product', $product->id, 'https://vkvideo.ru/video-1_2');

        $videos = ProductSchema::for($product)['subjectOf'] ?? [];

        $this->assertCount(1, $videos, 'the one without a poster is not stated');
        $this->assertSame('VideoObject', $videos[0]['@type']);
        $this->assertSame('Unboxing the camera', $videos[0]['name']);
    }

    public function test_a_card_marks_a_product_with_a_video(): void
    {
        $category = Category::create(['name' => ['en' => 'Cameras'], 'slug' => 'cameras', 'enabled' => true]);
        $with = $this->product('VID-W');
        $without = $this->product('VID-N');
        $with->categories()->attach($category->id);
        $without->categories()->attach($category->id);
        app(Videos::class)->add('product', $with->id, 'https://vimeo.com/111111');

        $html = $this->get('http://localhost/catalog/cameras')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'torgash-video-mark'));

        app(SettingsManager::class)->set('torgash.video', 'card_icon', false);
        $this->get('http://localhost/catalog/cameras')->assertDontSee('torgash-video-mark', false);
    }

    /** In the bin a product keeps its videos; gone for good, it takes them along. */
    public function test_videos_go_with_a_product_deleted_for_good(): void
    {
        $product = $this->product();
        app(Videos::class)->add('product', $product->id, 'https://youtu.be/dQw4w9WgXcQ');

        $product->delete();
        $this->assertSame(1, Video::query()->count());

        $product->forceDelete();
        $this->assertSame(0, Video::query()->count());
    }

    public function test_a_guest_cannot_add_a_video(): void
    {
        $product = $this->product();

        $this->postJson("/admin/video/product/{$product->id}", ['video' => 'https://youtu.be/dQw4w9WgXcQ'])->assertUnauthorized();
        $this->assertSame(0, Video::query()->count());
    }

    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }

    protected function product(string $sku = 'VID-1'): Product
    {
        return Product::create([
            'sku' => $sku,
            'name' => ['en' => 'Action camera '.$sku],
            'slug' => strtolower($sku),
            'price' => 100_00,
            'stock' => 3,
            'status' => 'active',
            'availability_status' => 'in_stock',
        ]);
    }

    protected function jpeg(): string
    {
        $image = imagecreatetruecolor(640, 360);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 40, 60));
        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }
}
