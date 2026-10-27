<?php

namespace Torgash\Media\Jobs;

use App\Core\Tasks\TrackedJob;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;
use Torgash\Media\ImageSettings;

/**
 * Re-runs every conversion against the stored originals, so changing the
 * thumbnail sizes or the WebP switch actually reaches images that were
 * uploaded under the old settings.
 *
 * This used to be Artisan::call('media-library:regenerate') inside the request
 * that handled the button. On a library of any size that is a request nobody
 * can wait out: PHP's time limit ends it halfway, the admin gets a dead page,
 * and the half that did run leaves no trace of how far it got.
 */
class RebuildThumbnails extends TrackedJob
{
    /**
     * Where a continuation picks up: a big library outlives one part's
     * budget (3105 images took 28 minutes on the live shop; an hour ended
     * it with TimeoutExceededException), so a part stops in time and the
     * next one resumes after the last id it finished.
     */
    public function __construct(
        public int $afterId = 0,
        public int $done = 0,
        public int $failed = 0,
    ) {}

    public function label(): string
    {
        return 'Rebuild thumbnails';
    }

    public function module(): ?string
    {
        return 'torgash.media';
    }

    protected function run(): void
    {
        $manipulator = app(FileManipulator::class);
        $total = Media::query()->count();
        $this->total($total);

        $done = $this->done;
        $failed = $this->failed;

        foreach (Media::query()->where('id', '>', $this->afterId)->orderBy('id')->cursor() as $media) {
            try {
                $manipulator->createDerivedFiles($media);
            } catch (Throwable $e) {
                // One unreadable file must not cost the admin the whole run —
                // the same call is what the vendor command does, and it also
                // keeps going. The count is reported at the end.
                $failed++;
            }

            $this->progress(++$done);

            if ($this->timeIsUp()) {
                $this->continueWith(new static($media->id, $done, $failed));

                return;
            }
        }

        $total = $done;

        $removed = $this->sweepOrphans();

        // One key with every number in it, rather than sentences glued together:
        // the translator gets the whole line and can reorder it freely.
        $this->result(
            ['images' => $total, 'failed' => $failed, 'files_removed' => $removed],
            ':count images rebuilt, :failed unreadable, :removed stale files deleted',
            ['count' => $total, 'failed' => $failed, 'removed' => $removed],
        );
    }

    /**
     * Deletes conversion files for sizes that are no longer configured.
     *
     * media-library only ever ADDS: drop "900x900" from the settings and its
     * files sit on disk forever, with the media row still claiming the
     * conversion exists — so Gallery::thumbUrl() would happily hand out a
     * thumbnail the admin thought they had removed. Rebuilding is the moment
     * the admin expects the settings to become the truth, so the sweep belongs
     * here rather than in a separate command nobody would run.
     *
     * Deliberately narrow: it only ever touches files whose name ends in our
     * own `-thumb-{W}x{H}.{ext}` marker inside a `conversions` directory, so an
     * unrelated file in the media folder cannot be caught by it.
     */
    protected function sweepOrphans(): int
    {
        $keep = array_column(ImageSettings::thumbSizes(), 'name');
        $removed = 0;

        foreach (Media::query()->cursor() as $media) {
            $generated = $media->generated_conversions ?? [];
            $stale = array_filter(
                array_diff(array_keys(array_filter($generated)), $keep),
                fn ($name) => (bool) preg_match('/^thumb-\d+x\d+$/', $name),
            );

            if ($stale === []) {
                continue;
            }

            // The conversions directory is read from the media file itself, not
            // from $media->getPath($name): that helper validates the name
            // against the REGISTERED conversions and throws for exactly the
            // removed ones this is here to clean up. Listed through the disk,
            // never globbed: the files may be in a bucket.
            $disk = \Illuminate\Support\Facades\Storage::disk($media->conversions_disk ?: $media->disk);
            $directory = ltrim(dirname(str_replace('\\', '/', $media->getPathRelativeToRoot())), './').'/conversions';
            $files = $disk->files($directory);

            foreach ($stale as $name) {
                foreach ($files as $file) {
                    if (str_ends_with(pathinfo($file, PATHINFO_FILENAME), '-'.$name) && $disk->delete($file)) {
                        $removed++;
                    }
                }

                $media->markAsConversionNotGenerated($name);
            }

            $media->save();
        }

        return $removed;
    }
}
