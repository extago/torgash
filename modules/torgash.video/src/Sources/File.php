<?php

namespace Torgash\Video\Sources;

use Illuminate\Support\Facades\Storage;

/**
 * A video file the shop uploaded itself (PLAN §50, step 2): mp4 or webm on
 * the shop's own disk — a folder or a bucket, whichever `public` is.
 *
 * It is never recognised from an address — a link is somebody else's video —
 * so `parse()` answers nothing; a file comes in only through `Uploads`. The
 * player is the browser's own <video> (`player.blade.php` asks
 * `instanceof File`), served as a plain file: seeking works through Range
 * requests, which both a web server and a bucket answer, so no streaming
 * server is needed.
 *
 * `ref` = {disk, path, mime, size}.
 */
class File implements VideoSource
{
    public const KEY = 'file';

    public function key(): string
    {
        return self::KEY;
    }

    public function parse(string $url): ?array
    {
        return null;
    }

    public function playerUrl(array $ref): string
    {
        $path = (string) ($ref['path'] ?? '');

        return $path === '' ? '' : Storage::disk((string) ($ref['disk'] ?? 'public'))->url($path);
    }

    /** Empty: the shop's own file loads from nowhere else, so the page has nothing to warn about. */
    public function platform(array $ref): string
    {
        return '';
    }

    public function details(array $ref, string $url): array
    {
        return [];
    }

    public function sandboxed(): bool
    {
        return false;
    }

    public function mime(array $ref): string
    {
        return (string) ($ref['mime'] ?? 'video/mp4');
    }
}
