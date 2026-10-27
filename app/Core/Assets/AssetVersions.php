<?php

namespace App\Core\Assets;

use Symfony\Component\HttpFoundation\Response;

/**
 * The version a file's address carries (`?v=`), so a browser that keeps a
 * script for a year is handed a NEW address the moment the file changes —
 * after a module update, a theme upload, a rebuild of the stylesheet
 * (PLAN §45.3). Without it an update reached the shop but not its customers.
 *
 * The version is the file's modification time and size, hashed short: the
 * stylesheet is replaced by a rename (StyleBuilder), a module by a folder
 * swap, so either moves it. Read once per file per request — a memo, scoped,
 * forgotten between requests, never a static: under a persistent worker a
 * static would keep last week's versions after a rebuild.
 */
class AssetVersions
{
    /** How long a browser keeps a file whose address names its current version. */
    public const FOREVER = 'public, max-age=31536000, immutable';

    /** Without a version, or with an old one: kept, but asked about every time (a 304 is cheap). */
    public const REVALIDATE = 'public, no-cache';

    /** @var array<string, string> */
    protected array $seen = [];

    public function of(string $absolutePath): string
    {
        if (! isset($this->seen[$absolutePath])) {
            clearstatcache(true, $absolutePath);
            $stamp = @filemtime($absolutePath);
            $this->seen[$absolutePath] = $stamp === false
                ? ''
                : substr(hash('xxh3', $stamp.':'.@filesize($absolutePath)), 0, 10);
        }

        return $this->seen[$absolutePath];
    }

    /** `/assets/css/app.css` → `/assets/css/app.css?v=…` (a file under public/). */
    public function public(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $version = $this->of(public_path(ltrim($path, '/')));

        return $version === '' ? $path : $path.'?v='.$version;
    }

    /**
     * Forever only when the address asked for exactly this file: an old `v`
     * (a page cached somewhere before the update) must not pin the new bytes
     * under an address a browser will keep for a year, nor the old ones.
     */
    public function cacheFor(string $absolutePath, ?string $asked): string
    {
        return $asked !== null && $asked !== '' && hash_equals($this->of($absolutePath), $asked)
            ? self::FOREVER
            : self::REVALIDATE;
    }

    public function forget(): void
    {
        $this->seen = [];
    }

    /** Sets the cache header a served asset gets for the version its address carried. */
    public function stamp(Response $response, string $absolutePath, ?string $asked): Response
    {
        $response->headers->set('Cache-Control', $this->cacheFor($absolutePath, $asked));

        return $response;
    }
}
