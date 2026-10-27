<?php

namespace Torgash\Video\Jobs;

use App\Core\Files\FileStorage;
use App\Core\Tasks\TaskCancelled;
use App\Core\Tasks\TrackedJob;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Torgash\Media\ImageProcessor;
use Torgash\Video\Ffmpeg;
use Torgash\Video\Models\Video;

/**
 * A file of the shop's own, made ready for the web by ffmpeg (PLAN §50,
 * step 4) — after the upload, in the background, because converting a
 * minute of video takes about as long as watching it:
 *
 *  - its length, read from the file;
 *  - a poster: a frame from the first tenth, unless the admin has given one;
 *  - what every browser plays: anything other than H.264 + AAC in mp4, or
 *    with its short side above the `file_max_side` setting, is converted;
 *    an mp4 that only has its index at the end gets it moved to the front
 *    (no conversion — a copy of the same picture).
 *
 * The uploaded file plays all along, and stays if anything fails: the row
 * says `state: failed` with ffmpeg's last word, and the admin sees it.
 * ffmpeg's refusal is the file's problem, not the worker's — it is written
 * on the row and the task, never thrown.
 */
class PrepareVideoFile extends TrackedJob
{
    public function __construct(public int $videoId) {}

    public function label(): string
    {
        return 'Prepare a video file for the web';
    }

    public function module(): ?string
    {
        return 'torgash.video';
    }

    /** Puts the video in the queue's hands and marks it so the admin sees it waiting. */
    public static function for(Video $video): void
    {
        $video->forceFill(['ref' => array_merge((array) $video->ref, ['state' => 'queued', 'error' => null])])->save();

        self::start($video->id);
    }

    protected function run(): void
    {
        $video = Video::query()->find($this->videoId);

        if (! $video || ! $video->isFile() || empty($video->ref['path'])) {
            return;
        }

        $ffmpeg = app(Ffmpeg::class);
        $files = app(FileStorage::class);
        $ref = (array) $video->ref;
        $disk = (string) ($ref['disk'] ?? 'public');
        $this->mark($video, 'working');
        $this->message('Video :id: reading the file', ['id' => $video->id]);

        $local = null;
        $frame = null;
        $out = null;

        try {
            $local = $files->localCopy($disk, (string) $ref['path']);
            $facts = $ffmpeg->probe($local);
            $duration = $facts['duration'];

            if ($duration !== null && ! $video->duration) {
                $video->duration = (int) round($duration);
            }

            // The poster, unless the admin uploaded one meanwhile.
            if (! $video->fresh()?->poster()) {
                $this->message('Video :id: taking a frame for the poster', ['id' => $video->id]);
                $frame = $files->tempPath('jpg');
                $ffmpeg->frame($local, $duration ? min($duration * 0.1, 10) : 0, $frame);
                app(ImageProcessor::class)->addFile($video, $frame, 'poster-'.$video->id, Video::POSTER);
            }

            $maxSide = max(240, (int) setting('torgash.video', 'file_max_side', 1080));
            $mp4 = str_contains($facts['format'], 'mp4');
            $plays = $mp4 && $facts['video'] === 'h264' && in_array($facts['audio'], [null, 'aac', 'mp3'], true);
            $tooBig = min($facts['width'], $facts['height']) > $maxSide;
            $this->stopIfAsked();

            if (! $plays || $tooBig) {
                $this->message('Video :id: converting for the web', ['id' => $video->id]);
                $total = $duration ? (int) ceil($duration) : 0;
                $this->total($total);
                $out = $files->tempPath('mp4');
                $ffmpeg->web($local, $out, $maxSide, fn (float $second) => $this->progress(min($total, (int) $second)));
            } elseif (! Ffmpeg::indexFirst($local)) {
                $this->message('Video :id: moving the index to the front', ['id' => $video->id]);
                $out = $files->tempPath('mp4');
                $ffmpeg->faststart($local, $out);
            }

            if ($out !== null) {
                $facts = $ffmpeg->probe($out);
                $ref = $this->replace($video, $ref, $out);
            }

            if (! Video::query()->whereKey($video->id)->exists()) {
                // Removed while it was being prepared: what was just written goes too.
                Storage::disk($disk)->deleteDirectory(dirname((string) $ref['path']));

                return;
            }

            $video->forceFill(['ref' => array_merge($ref, [
                'width' => $facts['width'],
                'height' => $facts['height'],
                'state' => null,
                'error' => null,
            ])])->save();

            $this->result(['video' => $video->id], 'Video :id is ready', ['id' => $video->id]);
        } catch (TaskCancelled $e) {
            $this->mark($video, null);

            throw $e;
        } catch (RuntimeException $e) {
            $this->mark($video, 'failed', $e->getMessage());
            $this->result(['video' => $video->id], 'Video :id: ffmpeg could not prepare it — :error', ['id' => $video->id, 'error' => $e->getMessage()]);
        } finally {
            $files->release($local);
            $files->release($frame);
            $files->release($out);
        }
    }

    /**
     * The prepared file takes the uploaded one's place under a name of its
     * own — a visitor's browser may hold pieces of the old one under the old
     * address — and the old one goes.
     *
     * @return array<string, mixed> the new ref
     */
    protected function replace(Video $video, array $ref, string $out): array
    {
        $disk = (string) ($ref['disk'] ?? 'public');
        $old = (string) $ref['path'];
        $stem = preg_replace('/-web$/', '', pathinfo($old, PATHINFO_FILENAME));
        $path = dirname($old).'/'.$stem.'-web.mp4';

        app(FileStorage::class)->putBack($disk, $path, $out);

        if ($path !== $old) {
            Storage::disk($disk)->delete($old);
        }

        return array_merge($ref, ['path' => $path, 'mime' => 'video/mp4', 'size' => (int) filesize($out)]);
    }

    /** What the admin's list shows beside the video while this runs. */
    protected function mark(Video $video, ?string $state, ?string $error = null): void
    {
        $fresh = Video::query()->find($video->id);

        if ($fresh) {
            $fresh->forceFill(['ref' => array_merge((array) $fresh->ref, ['state' => $state, 'error' => $error])])->save();
        }

        if ($video->isDirty('duration') && $fresh) {
            $fresh->forceFill(['duration' => $video->duration])->save();
        }
    }
}
