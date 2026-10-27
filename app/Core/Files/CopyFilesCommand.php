<?php

namespace App\Core\Files;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Copies the shop's files from one storage to the other — the step BEFORE
 * switching TORGASH_STORAGE, so the site never points at a bucket that does
 * not hold its pictures yet (and the way back, the step before switching off).
 *
 * Copies, never moves: the source is left as it was until somebody has seen
 * the site work from the target. Resumable: a file already at the target with
 * the same size is skipped, so a run cut off halfway is simply run again.
 * "Already there" is read from ONE listing of the target per folder, never a
 * HEAD per file: a bucket bills (and on a free tier caps) every request, and
 * two per file for a catalogue of photos is the whole day's allowance.
 *
 *   php artisan torgash:files-copy --to=s3 [--dry-run]
 *   php artisan torgash:files-copy --to=local
 *
 * What is copied is what something KNOWS about, never "whatever is in the
 * folder": each media row's own folder (original + conversions), and the
 * folders their owners declare through `core.files.directories` (theme
 * pictures, category icons, customers' option files). A folder collects what
 * nobody owns — a crashed upload, a test run, the engine's own scratch next to
 * the private files — and a bucket is paid for by the gigabyte.
 */
class CopyFilesCommand extends Command
{
    protected $signature = 'torgash:files-copy
        {--to= : s3 or local — where the files go}
        {--dry-run : list what would be copied, copy nothing}';

    protected $description = 'Copy the shop\'s files (photos, attachments, uploads) between the local folder and the S3 bucket';

    public function handle(): int
    {
        $to = (string) $this->option('to');

        if (! in_array($to, ['s3', 'local'], true)) {
            $this->error('Say where to: --to=s3 or --to=local');

            return self::FAILURE;
        }

        $from = $to === 's3' ? 'local' : 's3';

        if (! config('filesystems.disks.files-s3-public.bucket')) {
            $this->error('No bucket is configured: set AWS_BUCKET (and the keys, endpoint, region) in .env first.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $totals = ['copied' => 0, 'skipped' => 0, 'failed' => 0, 'bytes' => 0];

        foreach (FileStorage::DISKS as $disk) {
            $source = Storage::disk("files-$from-$disk");
            $target = Storage::disk("files-$to-$disk");
            $paths = $this->known($disk, $source);
            $there = $this->sizes($target, $paths);

            $this->line("<info>$disk</info>: ".count($paths)." files, $from → $to".($dry ? ' (dry run)' : ''));
            $bar = $this->output->createProgressBar(count($paths));

            foreach ($paths as $path) {
                $bar->advance();

                try {
                    $size = $source->size($path);

                    if (($there[$path] ?? null) === $size) {
                        $totals['skipped']++;

                        continue;
                    }

                    if (! $dry) {
                        $stream = $source->readStream($path);
                        $target->writeStream($path, $stream);
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }

                    $totals['copied']++;
                    $totals['bytes'] += $size;
                } catch (Throwable $e) {
                    $totals['failed']++;
                    $this->newLine();
                    $this->warn("$path: ".$e->getMessage());
                }
            }

            $bar->finish();
            $this->newLine();
        }

        $this->table(['copied', 'already there', 'failed', 'MB'], [[
            $totals['copied'], $totals['skipped'], $totals['failed'], round($totals['bytes'] / 1048576, 1),
        ]]);

        if (! $dry && $totals['failed'] === 0) {
            $this->info($to === 's3'
                ? 'Done. Now set TORGASH_STORAGE=s3 in .env, check the site and the storage screen, and only then remove the local copies.'
                : 'Done. Now set TORGASH_STORAGE=local in .env.');
        }

        return $totals['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * What the target already holds among these paths, path => size — ONE
     * listing of the whole disk (a bucket answers a thousand names per
     * request) instead of a request per file.
     *
     * @param  list<string>  $paths
     * @return array<string, int>
     */
    protected function sizes(Filesystem $target, array $paths): array
    {
        $wanted = array_flip($paths);
        $sizes = [];

        foreach ($target->listContents('', true) as $item) {
            if ($item instanceof \League\Flysystem\FileAttributes && isset($wanted[$item->path()])) {
                $sizes[$item->path()] = (int) $item->fileSize();
            }
        }

        return $sizes;
    }

    /**
     * Every file something knows about on one of the two disks.
     *
     * @return list<string>
     */
    protected function known(string $disk, Filesystem $source): array
    {
        $paths = [];

        // A media row owns its original and the conversions named after it —
        // not everything that happens to sit in a folder with its id.
        foreach (Media::query()->where('disk', $disk)->cursor() as $media) {
            $original = str_replace('\\', '/', $media->getPathRelativeToRoot());
            $folder = dirname($original);
            $stem = pathinfo($media->file_name, PATHINFO_FILENAME).'-';

            foreach ($source->allFiles($folder) as $file) {
                if ($file === $original
                    || (str_starts_with($file, $folder.'/conversions/') && str_starts_with(basename($file), $stem))
                    || (str_starts_with($file, $folder.'/responsive-images/') && str_starts_with(basename($file), $stem))) {
                    $paths[] = $file;
                }
            }
        }

        foreach (app(FileStorage::class)->directories() as $declared) {
            if ($declared['disk'] === $disk) {
                array_push($paths, ...$source->allFiles($declared['directory']));
            }
        }

        return array_values(array_unique($paths));
    }
}
