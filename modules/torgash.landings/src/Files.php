<?php

namespace Torgash\Landings;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Torgash\Landings\Models\Landing;

/**
 * A landing's own files: `landings/<id>/assets/…` on the public disk, which
 * its HTML and CSS name as `assets/…`.
 *
 * Only what a page shows — pictures, fonts, video — and each one checked by
 * its BYTES as well as its name: the public disk is served from the shop's
 * own address, so an "image" that is really a page with a script in it (an
 * SVG, an .html renamed) would run as the shop. SVG is refused for that
 * reason; a vector picture comes as a WebP/PNG or as markup in the HTML.
 */
class Files
{
    /** extension => what the bytes must be (finfo's MIME prefix) */
    public const TYPES = [
        'webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'avif' => 'image/avif',
        'woff2' => 'font/', 'woff' => 'font/', 'ttf' => 'font/', 'otf' => 'font/',
        'mp4' => 'video/mp4', 'webm' => 'video/webm',
    ];

    /** Per file, in bytes. */
    public const MAX_SIZE = 20 * 1024 * 1024;

    /** @return list<array{path: string, url: string, size: int}> under assets/, sorted */
    public function of(Landing $landing): array
    {
        $disk = Storage::disk('public');
        $root = $landing->filesDir().'/';
        $out = [];

        foreach ($disk->allFiles($root) as $file) {
            $out[] = ['path' => 'assets/'.substr($file, strlen($root)), 'url' => $disk->url($file), 'size' => (int) $disk->size($file)];
        }

        usort($out, fn ($a, $b) => strcmp($a['path'], $b['path']));

        return $out;
    }

    /**
     * Store one file under assets/[$folder/]; its name tidied to Latin letters,
     * digits, dots and dashes. Null when its name or bytes are not allowed.
     */
    public function put(Landing $landing, string $name, string $localPath, string $folder = ''): ?string
    {
        $relative = $this->clean(trim($folder, '/').'/'.$name);

        if ($relative === null || ! $this->allowed($relative, $localPath)) {
            return null;
        }

        $handle = fopen($localPath, 'rb');
        Storage::disk('public')->put($landing->filesDir().'/'.$relative, $handle);
        if (is_resource($handle)) {
            fclose($handle);
        }

        return 'assets/'.$relative;
    }

    public function delete(Landing $landing, string $path): void
    {
        $relative = $this->clean(Str::after($path, 'assets/'));

        if ($relative !== null) {
            Storage::disk('public')->delete($landing->filesDir().'/'.$relative);
        }
    }

    /** Copy every file of one landing to another (copying a landing). */
    public function copy(Landing $from, Landing $to): void
    {
        $disk = Storage::disk('public');

        foreach ($disk->allFiles($from->folder()) as $file) {
            $disk->copy($file, $to->folder().substr($file, strlen($from->folder())));
        }
    }

    /** Is this name one we keep, and are its bytes what the name says? */
    public function allowed(string $name, string $localPath): bool
    {
        $type = self::TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;

        if ($type === null || ! is_file($localPath) || filesize($localPath) > self::MAX_SIZE || filesize($localPath) === 0) {
            return false;
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($localPath);

        // finfo names fonts several ways (font/woff2, application/font-woff, application/vnd.ms-opentype).
        return str_starts_with($mime, $type)
            || ($type === 'font/' && preg_match('~(font|opentype|truetype|octet-stream)~', $mime) === 1);
    }

    /**
     * A path inside assets/: segments of [A-Za-z0-9._-] (anything else becomes a
     * dash, Cyrillic transliterated), no "..", no leading dot. Null if nothing is left.
     */
    public function clean(string $path): ?string
    {
        $segments = [];

        foreach (preg_split('~[\\\\/]+~', $path) as $segment) {
            $segment = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', Str::ascii($segment)), '-');

            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                continue;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }
}
