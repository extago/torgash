<?php

namespace Tests\Feature\Video;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Torgash\Blog\Models\Post;
use Torgash\Catalog\Models\Product;
use Torgash\Pages\Models\Page;
use Torgash\Video\Models\Video;
use Torgash\Video\Videos;

/**
 * Video beyond the product (PLAN §50, step 3): an article and a page carry
 * their own videos, registered by THEIR modules through `video.owners`;
 * `{video:ID}` in their text plays one of them — and only one of theirs; the
 * product exchange carries videos as links.
 */
class VideoInTextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsManager::class)->set('torgash.video', 'allowed_hosts', 'rutube.ru');
        Http::fake(['*' => Http::response('', 404)]);
    }

    public function test_an_article_carries_videos_and_its_text_plays_one(): void
    {
        $post = $this->article();
        $video = app(Videos::class)->add('blog_post', $post->id, 'https://youtu.be/dQw4w9WgXcQ');

        // The form shows the list with "Into the text".
        $this->actingAs($this->admin())->get('http://localhost/admin/blog/'.$post->id.'/edit')
            ->assertOk()->assertSee(__('Into the text'));

        // Titles ride along with the article's Save (the blog's own hook).
        $this->put('http://localhost/admin/blog/'.$post->id, [
            'title' => 'Phones in video', 'status' => 'published', 'body' => '<p>Look:</p><p>{video:'.$video->id.'}</p>',
            'videos' => [$video->id => ['title' => 'The unboxing', 'position' => 1]],
        ])->assertRedirect();
        $this->assertSame('The unboxing', $video->fresh()->t('title'));

        $html = $this->get('http://localhost/blog/'.$post->slug)->assertOk()->getContent();
        $this->assertStringContainsString('torgash-video', $html);
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $html);
        $this->assertStringNotContainsString('{video:', $html);
        // The player is still behind the press of play.
        $outside = preg_replace('#<template[^>]*data-video-player>.*?</template>#s', '', $html);
        $this->assertStringNotContainsString('<iframe', $outside);
    }

    public function test_a_text_cannot_play_another_records_video(): void
    {
        $page = Page::create(['slug' => 'video-page', 'title' => ['en' => 'About us'], 'body' => ['en' => '<p>x</p>'], 'enabled' => true]);
        $mine = app(Videos::class)->add('page', $page->id, 'https://vimeo.com/76979871');
        $theirs = app(Videos::class)->add('product', $this->product()->id, 'https://youtu.be/dQw4w9WgXcQ');

        $page->update(['body' => ['en' => '<p>{video:'.$mine->id.'}</p><p>{video:'.$theirs->id.'}</p>']]);

        $html = $this->get('http://localhost/video-page')->assertOk()->getContent();
        $this->assertStringContainsString('player.vimeo.com/video/76979871', $html);
        $this->assertStringNotContainsString('dQw4w9WgXcQ', $html);
    }

    public function test_an_article_deleted_takes_its_videos(): void
    {
        $post = $this->article();
        app(Videos::class)->add('blog_post', $post->id, 'https://youtu.be/dQw4w9WgXcQ');

        $post->delete();

        $this->assertSame(0, Video::query()->count());
    }

    public function test_the_product_exchange_carries_videos_as_links(): void
    {
        $product = $this->product();
        $videos = app(Videos::class);
        $videos->add('product', $product->id, 'https://youtu.be/dQw4w9WgXcQ');
        $videos->add('product', $product->id, 'https://vimeo.com/76979871');

        $this->assertSame('https://youtu.be/dQw4w9WgXcQ, https://vimeo.com/76979871', $videos->links('product', $product->id));

        // An import: Vimeo first, YouTube gone, a new allowed embed added, a stranger skipped.
        $unknown = $videos->syncLinks('product', $product->id, 'https://vimeo.com/76979871, https://rutube.ru/play/embed/abc https://evil.example/x');

        $this->assertSame(['https://evil.example/x'], $unknown);
        $this->assertSame(['vimeo', 'embed'], Video::query()->ownedBy('product', $product->id)->pluck('source')->all());
    }

    protected function article(): Post
    {
        return Post::create([
            'title' => ['en' => 'Phones in video'],
            'slug' => 'phones-in-video',
            'body' => ['en' => '<p>Text.</p>'],
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
    }

    protected function product(): Product
    {
        return Product::create(['sku' => 'VIT-1', 'name' => ['en' => 'Camera'], 'slug' => 'vit-1', 'price' => 100_00, 'stock' => 1, 'status' => 'active']);
    }

    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }
}
