<?php

namespace Tests\Feature\Video;

use App\Core\Settings\SettingsManager;
use App\Models\BackgroundTask;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Video\Ffmpeg;
use Torgash\Video\Models\Video;

/**
 * A file of the shop's own made ready by ffmpeg (PLAN §50, step 4): its
 * length and a poster frame, the index moved to the front, a big or
 * unplayable file converted — and a file ffmpeg cannot read left playing as
 * it came, with the reason on the row.
 *
 * Real ffmpeg on real (generated) clips: what is being proved is what ffmpeg
 * makes of them. The queue is `sync` here, so the background task runs
 * inside the upload's last request. Skipped where the machine has no ffmpeg.
 */
class VideoPrepareTest extends TestCase
{
    protected string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(Ffmpeg::class)->available()) {
            $this->markTestSkipped('ffmpeg is not on this machine.');
        }

        Storage::fake('public');
        app(SettingsManager::class)->set('torgash.video', 'file_max_mb', 5);
        $this->scratch = storage_path('app/files-tmp/test-clips');
        @mkdir($this->scratch, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratch.'/*') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_a_big_file_is_converted_down_and_gets_its_poster_and_length(): void
    {
        app(SettingsManager::class)->set('torgash.video', 'file_max_side', 240);
        $product = $this->product();
        $clip = $this->clip('wide.mp4', 640, 360, ['-c:v', 'libx264', '-c:a', 'aac']);
        $this->assertFalse(Ffmpeg::indexFirst($clip), 'ffmpeg writes the index at the end unless asked');

        $this->upload($product, $clip, 'Обзор.mp4')
            ->assertOk()
            ->assertJsonPath('errors', [])
            ->assertJsonPath('items.0.prepared', true)
            ->assertJsonPath('items.0.size', '426×240')
            ->assertJsonPath('items.0.duration', '00:02');

        $video = Video::query()->sole();
        $this->assertNull($video->ref['state']);
        $this->assertSame(2, $video->duration);
        $this->assertNotNull($video->poster(), 'a frame became the poster');
        $this->assertStringEndsWith('-web.mp4', $video->ref['path']);
        $this->assertSame([basename($video->ref['path'])], array_map('basename', Storage::disk('public')->files(dirname($video->ref['path']))), 'the uploaded file is gone, the prepared one stays');

        $prepared = $this->local($video);
        $facts = app(Ffmpeg::class)->probe($prepared);
        $this->assertSame([426, 240, 'h264', 'aac'], [$facts['width'], $facts['height'], $facts['video'], $facts['audio']]);
        $this->assertTrue(Ffmpeg::indexFirst($prepared));
        $this->assertSame('Prepare a video file for the web', BackgroundTask::query()->latest('id')->value('name'));
        $this->assertSame(BackgroundTask::DONE, BackgroundTask::query()->latest('id')->value('status'));
    }

    public function test_a_tall_phone_video_is_measured_by_its_short_side(): void
    {
        app(SettingsManager::class)->set('torgash.video', 'file_max_side', 240);
        $product = $this->product();
        $clip = $this->clip('tall.mp4', 360, 640, ['-c:v', 'libx264', '-an']);

        $this->upload($product, $clip, 'tall.mp4')->assertOk()->assertJsonPath('items.0.size', '240×426');
    }

    public function test_a_file_that_already_plays_only_gets_its_index_moved(): void
    {
        $product = $this->product();
        $clip = $this->clip('small.mp4', 320, 240, ['-c:v', 'libx264', '-c:a', 'aac']);
        $before = app(Ffmpeg::class)->probe($clip);

        $this->upload($product, $clip, 'small.mp4')->assertOk()->assertJsonPath('items.0.size', '320×240');

        $prepared = $this->local(Video::query()->sole());
        $this->assertTrue(Ffmpeg::indexFirst($prepared));
        // The same picture, copied rather than converted: the same streams and length.
        $after = app(Ffmpeg::class)->probe($prepared);
        $this->assertSame([$before['video'], $before['audio'], $before['width']], [$after['video'], $after['audio'], $after['width']]);
        $this->assertEqualsWithDelta(filesize($clip), filesize($prepared), filesize($clip) * 0.02, 'the same bytes, only reordered');
    }

    public function test_a_webm_becomes_an_mp4_every_browser_plays(): void
    {
        $product = $this->product();
        $clip = $this->clip('clip.webm', 320, 240, ['-c:v', 'libvpx-vp9', '-an']);

        $this->upload($product, $clip, 'clip.webm')->assertOk();

        $video = Video::query()->sole();
        $this->assertSame('video/mp4', $video->ref['mime']);
        $this->assertStringEndsWith('-web.mp4', $video->ref['path']);
        $this->assertSame('h264', app(Ffmpeg::class)->probe($this->local($video))['video']);
    }

    public function test_a_poster_of_the_admins_own_is_kept(): void
    {
        $product = $this->product();
        $clip = $this->clip('small.mp4', 320, 240, ['-c:v', 'libx264', '-an']);
        $this->upload($product, $clip, 'small.mp4', prepare: false);
        $video = Video::query()->sole();
        $mine = UploadedFile::fake()->image('mine.jpg', 640, 360);
        $video->addMedia($mine->getRealPath())->usingFileName('mine.jpg')->toMediaCollection(Video::POSTER);

        $this->postJson("/admin/video/product/{$product->id}/{$video->id}/prepare")->assertOk()->assertJsonPath('items.0.prepared', true);

        $this->assertSame('mine.jpg', $video->fresh()->poster()->file_name);
    }

    public function test_a_file_ffmpeg_cannot_read_stays_as_it_came_with_the_reason(): void
    {
        $product = $this->product();
        $junk = $this->scratch.'/junk.mp4';
        file_put_contents($junk, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 5000));

        $this->upload($product, $junk, 'junk.mp4')
            ->assertOk()
            ->assertJsonPath('items.0.state', 'failed')
            ->assertJsonPath('items.0.prepared', false);

        $video = Video::query()->sole();
        $this->assertNotSame('', (string) $video->ref['error']);
        $this->assertTrue(Storage::disk('public')->exists($video->ref['path']), 'the uploaded file still plays');
        // Told on the task too, which is not a crash of the worker.
        $this->assertSame(BackgroundTask::DONE, BackgroundTask::query()->latest('id')->value('status'));
    }

    /** A generated clip: two seconds of test picture (and a tone unless `-an`). */
    protected function clip(string $name, int $width, int $height, array $codecs): string
    {
        $path = $this->scratch.'/'.$name;
        $process = new Process([
            app(Ffmpeg::class)->binary(), '-hide_banner', '-nostdin', '-y',
            '-f', 'lavfi', '-i', "testsrc=size={$width}x{$height}:rate=15:duration=2",
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2',
            '-shortest', ...$codecs, $path,
        ]);
        $process->mustRun();

        return $path;
    }

    /** The whole upload, as the editor sends it: start, one piece, finish. */
    protected function upload(Product $product, string $file, string $name, bool $prepare = true): TestResponse
    {
        if (! $prepare) {
            app(SettingsManager::class)->set('torgash.video', 'ffmpeg_path', 'no-such-ffmpeg');
        }

        $bytes = (string) file_get_contents($file);
        $this->actingAs(User::role('admin')->firstOrFail());
        $token = $this->postJson("/admin/video/product/{$product->id}/upload", ['name' => $name, 'size' => strlen($bytes)])->assertOk()->json('token');

        $this->call('POST', "/admin/video/product/{$product->id}/upload/{$token}", [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_UPLOAD_OFFSET' => '0',
        ], $bytes)->assertOk();

        $response = $this->postJson("/admin/video/product/{$product->id}/upload/{$token}/finish");

        app(SettingsManager::class)->set('torgash.video', 'ffmpeg_path', '');

        return $response;
    }

    protected function local(Video $video): string
    {
        $path = $this->scratch.'/prepared-'.$video->id.'.'.pathinfo($video->ref['path'], PATHINFO_EXTENSION);
        file_put_contents($path, Storage::disk('public')->get($video->ref['path']));

        return $path;
    }

    protected function product(): Product
    {
        return Product::create([
            'sku' => 'VID-P',
            'name' => ['en' => 'Action camera'],
            'slug' => 'vid-p',
            'price' => 100_00,
            'stock' => 3,
            'status' => 'active',
            'availability_status' => 'in_stock',
        ]);
    }
}
