<?php

namespace Torgash\Video\Sources;

use RuntimeException;

/**
 * The platforms a video can play on, and the one place an address the admin
 * typed is turned into one.
 *
 * Another module adds a platform by answering `video.sources` (a list of
 * VideoSource, asked once, on first use, so provider boot order does not
 * matter). They are asked in order and the first that recognises the address
 * keeps it; the shop's own embed code (`Embed`) is always asked last, so a
 * platform known by name is never stored as somebody's iframe.
 */
class Sources
{
    /** @var array<string, VideoSource>|null */
    protected ?array $sources = null;

    /** @return array<string, VideoSource> */
    public function all(): array
    {
        if ($this->sources === null) {
            $listed = hook_filter('video.sources', [new YouTube, new Vimeo, new VkVideo, new Bilibili, new File]);
            $listed[] = new Embed;

            $this->sources = [];

            foreach ($listed as $source) {
                if ($source instanceof VideoSource) {
                    $this->sources[$source->key()] ??= $source;
                }
            }
        }

        return $this->sources;
    }

    public function get(string $key): ?VideoSource
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * What the admin pasted — a page's address, a player's, or a whole
     * <iframe> — as a platform and what it needs.
     *
     * @return array{source: VideoSource, ref: array<string, scalar>, url: string}
     *
     * @throws RuntimeException with a sentence for the admin
     */
    public function resolve(string $typed): array
    {
        $url = self::address($typed);

        if ($url === null) {
            throw new RuntimeException(__('Paste a link to the video or its embed code.'));
        }

        foreach ($this->all() as $source) {
            if (($ref = $source->parse($url)) !== null) {
                return ['source' => $source, 'ref' => $ref, 'url' => $url];
            }
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        throw new RuntimeException(strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            ? __('A video from another site has to be on an https:// address.')
            : __('Video from :host is not accepted. If you trust this site, add it to "Sites whose embed code is accepted" in the Video module\'s settings.', ['host' => $host]));
    }

    /**
     * The one address inside what was pasted: the `src` of an <iframe> (the
     * rest of the code is thrown away, never stored), else the text itself
     * when it is an address at all.
     */
    public static function address(string $typed): ?string
    {
        $typed = trim($typed);

        if (preg_match('#<iframe\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1#is', $typed, $m)) {
            $typed = html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5);
        } elseif (str_contains($typed, '<')) {
            return null;
        }

        if (str_starts_with($typed, '//')) {
            $typed = 'https:'.$typed;
        } elseif (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $typed) && preg_match('#^[\w.-]+\.[a-z]{2,}(/|$)#i', $typed)) {
            // "youtu.be/xyz", copied without its scheme.
            $typed = 'https://'.$typed;
        }

        return filter_var($typed, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($typed, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $typed
            : null;
    }
}
