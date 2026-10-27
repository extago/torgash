<?php

namespace App\Core\Files;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * The shop's own files, wherever they live (config/filesystems.php): a local
 * folder or an S3-compatible bucket. The rule this class exists for is that
 * nothing reads a stored file by its local PATH — a path means nothing when
 * the file is in a bucket — so the few places that must hand a real file to a
 * library (rotating a photo, measuring it) ask for a LOCAL COPY here, work on
 * it and put it back. One path for both storages, which is what lets the
 * suite, running on a local folder, prove the bucket's code too.
 */
class FileStorage
{
    /** The disks that hold the shop's files (the rest are the engine's scratch). */
    public const DISKS = ['public', 'private'];

    /**
     * The folders on the two disks that are somebody's files but not a media
     * row's — declared by their owner through `core.files.directories`
     * (`{disk, directory, label, files?, bytes?}`), because core may not name
     * a module's table and a folder's contents are not an answer to "whose".
     * Core declares its own (theme pictures); the copy command and the storage
     * screen read the list.
     *
     * @return list<array{disk: string, directory: string, label: string, files: ?int, bytes: ?int}>
     */
    public function directories(): array
    {
        $declared = (array) hook_filter('core.files.directories', [[
            'disk' => 'public',
            'directory' => \App\Core\Themes\ThemeSettings::IMAGE_DIR,
            'label' => 'Theme pictures',
        ]]);

        return array_values(array_filter(array_map(fn ($entry) => is_array($entry) && isset($entry['disk'], $entry['directory'])
            && in_array($entry['disk'], self::DISKS, true) ? [
                'disk' => $entry['disk'],
                'directory' => trim((string) $entry['directory'], '/'),
                'label' => (string) ($entry['label'] ?? $entry['directory']),
                'files' => isset($entry['files']) ? (int) $entry['files'] : null,
                'bytes' => isset($entry['bytes']) ? (int) $entry['bytes'] : null,
            ] : null, $declared)));
    }

    public function onS3(): bool
    {
        // Asked of the configured disk, never env(): with the config cached
        // env() answers null and a shop on S3 would think it was local.
        return $this->driver('public') === 's3';
    }

    public function driver(string $disk = 'public'): string
    {
        return (string) config("filesystems.disks.$disk.driver", 'local');
    }

    /**
     * A temporary local file holding what is stored at `$path` on `$disk`.
     * The caller deletes it (`release()`), whatever happened in between.
     */
    public function localCopy(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException(__('The original file for this image is missing.'));
        }

        $target = $this->tempPath(pathinfo($path, PATHINFO_EXTENSION));
        $out = fopen($target, 'wb');

        try {
            stream_copy_to_stream($stream, $out);
        } finally {
            fclose($out);
            fclose($stream);
        }

        return $target;
    }

    /** The original of a media item (or one of its conversions) as a local copy. */
    public function mediaCopy(Media $media, string $conversion = ''): string
    {
        $disk = $conversion === '' ? $media->disk : $media->conversions_disk;

        return $this->localCopy($disk, $media->getPathRelativeToRoot($conversion));
    }

    /** Write a local file back to where it came from, keeping the disk's own visibility. */
    public function putBack(string $disk, string $path, string $localFile): void
    {
        $in = fopen($localFile, 'rb');

        try {
            Storage::disk($disk)->writeStream($path, $in);
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }
    }

    public function release(?string $localFile): void
    {
        if ($localFile !== null && is_file($localFile)) {
            @unlink($localFile);
        }
    }

    /**
     * Does the disk answer: write a small file, read it back, delete it. For
     * the storage screen's button and the guard's statement — a bucket whose
     * key was revoked leaves every page looking fine until somebody uploads.
     *
     * @return array{ok: bool, ms: int, error: ?string}
     */
    public function probe(string $disk): array
    {
        $started = microtime(true);
        $path = '.torgash-probe/'.Str::random(16).'.txt';
        $body = 'torgash '.now()->toIso8601String();

        try {
            $storage = Storage::disk($disk);
            $storage->put($path, $body);
            $read = $storage->get($path);
            $storage->delete($path);

            $ok = $read === $body;
            $error = $ok ? null : __('The file read back was not the file written.');
        } catch (Throwable $e) {
            $ok = false;
            $error = Str::limit($e->getMessage(), 300);
        }

        return ['ok' => $ok, 'ms' => (int) round((microtime(true) - $started) * 1000), 'error' => $error];
    }

    /**
     * What the storage IS, in words the screen prints — never a secret: the
     * key id is shown by its last four characters and the secret not at all.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $public = (array) config('filesystems.disks.public');
        $private = (array) config('filesystems.disks.private');
        $key = (string) ($public['key'] ?? '');
        $acl = ($public['visibility'] ?? null) === 'public';

        return [
            'kind' => $this->onS3() ? 's3' : 'local',
            'bucket' => $public['bucket'] ?? null,
            'private_bucket' => $private['bucket'] ?? null,
            // One bucket published as a whole holds the private files too:
            // an enquiry's photo then has a public address. Only per-file
            // ACLs (AWS) keep a shared bucket's private half private.
            'private_exposed' => $this->onS3() && ! $acl
                && ($private['bucket'] ?? null) === ($public['bucket'] ?? null),
            'endpoint' => $public['endpoint'] ?? null,
            'region' => $public['region'] ?? null,
            'public_url' => $public['url'] ?? null,
            'acl' => $acl,
            'key' => $key !== '' ? '…'.substr($key, -4) : null,
            'public_root' => $this->onS3() ? 'public/' : storage_path('app/public'),
            'private_root' => $this->onS3() ? 'private/' : storage_path('app/private'),
        ];
    }

    /**
     * A name for a scratch file on THIS server (nothing is created) — for a
     * program that writes its result to a path, like ffmpeg. The caller
     * deletes it with `release()`.
     */
    public function tempPath(string $extension): string
    {
        $dir = storage_path('app/files-tmp');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir.DIRECTORY_SEPARATOR.Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
    }
}
