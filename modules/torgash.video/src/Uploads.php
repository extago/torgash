<?php

namespace Torgash\Video;

use App\Core\Files\FileStorage;
use Illuminate\Support\Str;
use RuntimeException;
use Torgash\Video\Models\Video;
use Torgash\Video\Sources\File;

/**
 * A video file uploaded in pieces (PLAN §50, step 2).
 *
 * A video is hundreds of megabytes and php.ini's `upload_max_filesize` is
 * often 2–64: one request cannot carry it, and the admin cannot change
 * php.ini. So the browser sends it as raw chunks no bigger than the server
 * takes in one request — start, chunk × n, finish — appended to a scratch
 * file on THIS server (`storage/app/video-uploads`, never the bucket: an
 * append is not something S3 does). Only the finished, checked file goes to
 * the shop's disk through FileStorage, so a folder and a bucket take the same
 * path.
 *
 * Each upload is a token with a small JSON beside its part file: who started
 * it, for which record, how big it said it is. A chunk is accepted only at the
 * offset the part file has reached — a retry of a chunk that already arrived
 * is answered with the size rather than appended twice. Parts nobody finished
 * are swept by the schedule (`sweep()`).
 */
class Uploads
{
    /** What is accepted, by extension — and checked again by the file's own first bytes. */
    public const TYPES = ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm'];

    /** A chunk never bigger than this, whatever the server would take. */
    protected const CHUNK_CAP = 8 * 1024 * 1024;

    public function __construct(protected FileStorage $files) {}

    /** The largest file the shop accepts, in bytes (the module's setting). */
    public function maxBytes(): int
    {
        return max(1, (int) setting('torgash.video', 'file_max_mb', 200)) * 1024 * 1024;
    }

    /**
     * How big one chunk may be: below what the server takes in one request
     * body (`post_max_size`, with room for headers), and never above 8 MB, so
     * a slow line shows progress and a dropped chunk costs little.
     */
    public function chunkBytes(): int
    {
        $post = self::iniBytes((string) ini_get('post_max_size'));
        $limit = $post > 0 ? $post - 64 * 1024 : self::CHUNK_CAP;

        return max(256 * 1024, min(self::CHUNK_CAP, $limit));
    }

    /**
     * @return array{token: string, chunk: int}
     *
     * @throws RuntimeException with a sentence for the admin
     */
    public function start(string $ownerType, int $ownerId, string $name, int $size, ?int $userId): array
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! isset(self::TYPES[$extension])) {
            throw new RuntimeException(__('A video file has to be mp4 or webm.'));
        }

        if ($size <= 0) {
            throw new RuntimeException(__('The file is empty.'));
        }

        if ($size > $this->maxBytes()) {
            throw new RuntimeException(__('The file is :size MB; the shop takes videos up to :max MB (the Video module\'s settings).', [
                'size' => (int) ceil($size / 1048576),
                'max' => (int) ($this->maxBytes() / 1048576),
            ]));
        }

        $token = Str::random(40);
        $this->ensureFolder();
        touch($this->partPath($token));
        file_put_contents($this->metaPath($token), json_encode([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'user_id' => $userId,
            'name' => mb_substr(basename($name), 0, 200),
            'extension' => $extension,
            'size' => $size,
        ]));

        return ['token' => $token, 'chunk' => $this->chunkBytes()];
    }

    /**
     * One chunk, as the raw request body, meant for `$offset`. Returns how
     * much of the file has arrived.
     *
     * @param  resource  $body
     *
     * @throws RuntimeException with a sentence for the admin
     */
    public function append(string $token, string $ownerType, int $ownerId, ?int $userId, int $offset, $body): int
    {
        $meta = $this->meta($token, $ownerType, $ownerId, $userId);
        $part = $this->partPath($token);
        clearstatcache(true, $part);
        $have = (int) filesize($part);

        // A retry of a chunk that already arrived: say where the file is.
        if ($offset < $have) {
            return $have;
        }

        if ($offset > $have) {
            throw new RuntimeException(__('A piece of the file went missing on the way. Upload it again.'));
        }

        $out = fopen($part, 'ab');

        try {
            $written = stream_copy_to_stream($body, $out, $this->chunkBytes() + 1);
        } finally {
            fclose($out);
        }

        clearstatcache(true, $part);
        $have = (int) filesize($part);

        if ($written > $this->chunkBytes() || $have > $meta['size']) {
            $this->discard($token);

            throw new RuntimeException(__('The file is bigger than it said it was. Upload it again.'));
        }

        return $have;
    }

    /**
     * The whole file has arrived: check it IS a video, put it on the shop's
     * disk and make it a video of the record.
     *
     * @throws RuntimeException with a sentence for the admin
     */
    public function finish(string $token, string $ownerType, int $ownerId, ?int $userId): Video
    {
        $meta = $this->meta($token, $ownerType, $ownerId, $userId);
        $part = $this->partPath($token);
        clearstatcache(true, $part);

        try {
            if ((int) filesize($part) !== (int) $meta['size']) {
                throw new RuntimeException(__('The file did not arrive whole. Upload it again.'));
            }

            $mime = self::sniff($part);

            if ($mime === null) {
                throw new RuntimeException(__('This is not an mp4 or webm video, whatever its name says.'));
            }

            $video = Video::query()->create([
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'source' => File::KEY,
                'ref' => [],
                'url' => $meta['name'],
                'position' => (int) Video::query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->max('position') + 1,
            ]);

            $stem = Str::slug(pathinfo($meta['name'], PATHINFO_FILENAME)) ?: 'video';
            $path = "videos/{$ownerType}/{$video->id}/".mb_substr($stem, 0, 80).'.'.($mime === 'video/webm' ? 'webm' : 'mp4');

            try {
                $this->files->putBack('public', $path, $part);
            } catch (\Throwable $e) {
                $video->delete();

                throw $e;
            }

            $video->forceFill(['ref' => ['disk' => 'public', 'path' => $path, 'mime' => $mime, 'size' => (int) $meta['size']]])->save();

            return $video;
        } finally {
            $this->discard($token);
        }
    }

    public function discard(string $token): void
    {
        if (! self::validToken($token)) {
            return;
        }

        @unlink($this->partPath($token));
        @unlink($this->metaPath($token));
    }

    /** Parts nobody finished — a closed tab, a lost line — older than a day. */
    public function sweep(): int
    {
        $swept = 0;

        foreach (glob($this->folder().'/*') ?: [] as $file) {
            if (filemtime($file) < time() - 86400) {
                @unlink($file);
                $swept++;
            }
        }

        return $swept;
    }

    /**
     * What the file's own first bytes say it is: an ISO media file (`ftyp`
     * at byte 4 — mp4, m4v) or Matroska/WebM (the EBML magic). The name is
     * the admin's; these bytes are the file's.
     */
    public static function sniff(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 12);

        if (strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp') {
            return 'video/mp4';
        }

        if (str_starts_with($head, "\x1A\x45\xDF\xA3")) {
            return 'video/webm';
        }

        return null;
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '0' || $value === '-1') {
            return 0;
        }

        $number = (float) $value;

        return (int) match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** @return array{owner_type: string, owner_id: int, user_id: ?int, name: string, extension: string, size: int} */
    protected function meta(string $token, string $ownerType, int $ownerId, ?int $userId): array
    {
        $meta = self::validToken($token) && is_file($this->metaPath($token)) && is_file($this->partPath($token))
            ? json_decode((string) file_get_contents($this->metaPath($token)), true)
            : null;

        // Someone else's upload, or another record's, is the same as none.
        if (! is_array($meta) || $meta['owner_type'] !== $ownerType || (int) $meta['owner_id'] !== $ownerId || ($meta['user_id'] ?? null) !== $userId) {
            throw new RuntimeException(__('This upload has expired. Upload the file again.'));
        }

        return $meta;
    }

    protected static function validToken(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{40}$/', $token);
    }

    protected function folder(): string
    {
        return storage_path('app/video-uploads');
    }

    protected function ensureFolder(): void
    {
        if (! is_dir($this->folder())) {
            mkdir($this->folder(), 0775, true);
        }
    }

    protected function partPath(string $token): string
    {
        return $this->folder().'/'.$token.'.part';
    }

    protected function metaPath(string $token): string
    {
        return $this->folder().'/'.$token.'.json';
    }
}
