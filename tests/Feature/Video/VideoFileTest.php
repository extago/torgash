<?php

namespace Tests\Feature\Video;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Video\Models\Video;
use Torgash\Video\Uploads;

/**
 * A video file of the shop's own (PLAN §50, step 2): sent in pieces so
 * php.ini is not the ceiling, checked by its own bytes rather than its name,
 * stored on the shop's disk, played by the browser's own <video> — and gone
 * with its row.
 */
class VideoFileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        app(SettingsManager::class)->set('torgash.video', 'file_max_mb', 5);
        // The upload itself, as on a server without ffmpeg — what ffmpeg
        // does afterwards is VideoPrepareTest's.
        app(SettingsManager::class)->set('torgash.video', 'ffmpeg_path', 'no-such-ffmpeg');
    }

    public function test_a_file_arrives_in_pieces_and_becomes_a_video(): void
    {
        $product = $this->product();
        $bytes = $this->mp4(300_000);
        $this->actingAs($this->admin());

        $token = $this->postJson("/admin/video/product/{$product->id}/upload", ['name' => 'Обзор камеры.MP4', 'size' => strlen($bytes)])
            ->assertOk()->json('token');

        // Two pieces, the first sent twice — a retry after a lost answer is
        // answered with where the file is, never appended again.
        $half = intdiv(strlen($bytes), 2);
        $this->chunk($product, $token, 0, substr($bytes, 0, $half))->assertJsonPath('received', $half);
        $this->chunk($product, $token, 0, substr($bytes, 0, $half))->assertJsonPath('received', $half);
        $this->chunk($product, $token, $half, substr($bytes, $half))->assertJsonPath('received', strlen($bytes));

        $this->postJson("/admin/video/product/{$product->id}/upload/{$token}/finish")
            ->assertOk()
            ->assertJsonPath('errors.0', __('Upload a poster for this video — until then the visitor sees a plain frame with a play button.'))
            ->assertJsonPath('items.0.file', true)
            ->assertJsonPath('items.0.url', 'Обзор камеры.MP4');

        $video = Video::query()->sole();
        $this->assertTrue($video->isFile());
        $this->assertSame('video/mp4', $video->ref['mime']);
        $this->assertSame($bytes, Storage::disk('public')->get($video->ref['path']));
        $this->assertStringStartsWith("videos/product/{$video->id}/", $video->ref['path']);
        $this->assertSame([], glob(storage_path('app/video-uploads/'.$token.'*')), 'the scratch copy is gone');

        // The product page: the browser's own player, inside the template, with the file.
        $html = $this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<template data-video-player>\s*<video src="[^"]*'.preg_quote(basename($video->ref['path']), '#').'"#', $html);
        $this->assertStringNotContainsString(__('The video loads from :platform', ['platform' => '']), $html);

        // Removing the video removes the file.
        $this->deleteJson("/admin/video/product/{$product->id}/{$video->id}")->assertOk();
        $this->assertFalse(Storage::disk('public')->exists($video->ref['path']));
    }

    public function test_a_file_is_judged_by_its_bytes_not_its_name(): void
    {
        $product = $this->product();
        $bytes = str_repeat('not a video ', 100);
        $this->actingAs($this->admin());

        $token = $this->postJson("/admin/video/product/{$product->id}/upload", ['name' => 'clip.mp4', 'size' => strlen($bytes)])->json('token');
        $this->chunk($product, $token, 0, $bytes)->assertOk();

        $this->postJson("/admin/video/product/{$product->id}/upload/{$token}/finish")
            ->assertJsonPath('errors.0', __('This is not an mp4 or webm video, whatever its name says.'));
        $this->assertSame(0, Video::query()->count());
    }

    public function test_the_limit_and_the_types_are_said_before_anything_is_sent(): void
    {
        $product = $this->product();
        $this->actingAs($this->admin());

        $this->postJson("/admin/video/product/{$product->id}/upload", ['name' => 'big.mp4', 'size' => 6 * 1024 * 1024])
            ->assertStatus(422)
            ->assertJsonPath('errors.0', __('The file is :size MB; the shop takes videos up to :max MB (the Video module\'s settings).', ['size' => 6, 'max' => 5]));

        $this->postJson("/admin/video/product/{$product->id}/upload", ['name' => 'clip.avi', 'size' => 1000])
            ->assertStatus(422)
            ->assertJsonPath('errors.0', __('A video file has to be mp4 or webm.'));
    }

    public function test_a_piece_beyond_the_declared_size_or_out_of_order_is_refused(): void
    {
        $product = $this->product();
        $bytes = $this->mp4(1000);
        $this->actingAs($this->admin());

        $token = $this->postJson("/admin/video/product/{$product->id}/upload", ['name' => 'clip.mp4', 'size' => strlen($bytes)])->json('token');

        $this->chunk($product, $token, 500, substr($bytes, 500))
            ->assertStatus(422)->assertJsonPath('errors.0', __('A piece of the file went missing on the way. Upload it again.'));

        $this->chunk($product, $token, 0, $bytes.'extra')
            ->assertStatus(422)->assertJsonPath('errors.0', __('The file is bigger than it said it was. Upload it again.'));
    }

    public function test_somebody_elses_upload_is_not_yours_to_continue(): void
    {
        $product = $this->product();
        $other = $this->product('VID-2');
        $bytes = $this->mp4(1000);
        $this->actingAs($this->admin());

        $token = $this->postJson("/admin/video/product/{$product->id}/upload", ['name' => 'clip.mp4', 'size' => strlen($bytes)])->json('token');

        // Another record's address with this token is an expired upload.
        $this->chunk($other, $token, 0, $bytes)
            ->assertStatus(422)->assertJsonPath('errors.0', __('This upload has expired. Upload the file again.'));
    }

    public function test_halves_nobody_finished_are_swept_after_a_day(): void
    {
        $uploads = app(Uploads::class);
        // The folder is the real one, and other tests leave halves in it that
        // turn a day old by the next day's run: sweep THEM first, or this
        // counts yesterday's leftovers as its own.
        $uploads->sweep();
        $started = $uploads->start('product', $this->product()->id, 'clip.mp4', 1000, null);
        $part = storage_path('app/video-uploads/'.$started['token'].'.part');

        $this->assertSame(0, $uploads->sweep());
        touch($part, time() - 90000);
        touch(substr($part, 0, -5).'.json', time() - 90000);

        $this->assertSame(2, $uploads->sweep());
        $this->assertFileDoesNotExist($part);
    }

    protected function chunk(Product $product, string $token, int $offset, string $bytes): TestResponse
    {
        return $this->call('POST', "/admin/video/product/{$product->id}/upload/{$token}", [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_UPLOAD_OFFSET' => (string) $offset,
        ], $bytes);
    }

    /** An ISO media header and padding — what the sniffing reads. */
    protected function mp4(int $size): string
    {
        $head = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

        return $head.str_repeat("\x00", $size - strlen($head));
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
}
