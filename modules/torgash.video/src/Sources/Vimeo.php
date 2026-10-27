<?php

namespace Torgash\Video\Sources;

/**
 * Vimeo. An unlisted video carries a second, private part in its address
 * (`vimeo.com/123/abcdef`, `?h=abcdef`) without which the player refuses it;
 * it is kept as `h`.
 */
class Vimeo extends KnownPlatform
{
    protected const HOSTS = ['vimeo.com'];

    public function key(): string
    {
        return 'vimeo';
    }

    public function platform(array $ref): string
    {
        return 'Vimeo';
    }

    public function parse(string $url): ?array
    {
        if (! $this->owns($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        // vimeo.com/123, vimeo.com/123/hash, player.vimeo.com/video/123,
        // vimeo.com/channels/x/123, vimeo.com/groups/x/videos/123
        if (! preg_match('#/(\d{3,})(?:/([0-9a-f]{6,}))?/?$#i', $path, $m)) {
            return null;
        }

        $hash = $m[2] ?? ($this->query($url)['h'] ?? '');

        return array_filter(['id' => $m[1], 'h' => preg_match('/^[0-9a-f]+$/i', $hash) ? $hash : null]);
    }

    public function playerUrl(array $ref): string
    {
        $url = 'https://player.vimeo.com/video/'.rawurlencode((string) ($ref['id'] ?? '')).'?autoplay=1&dnt=1';

        return empty($ref['h']) ? $url : $url.'&h='.rawurlencode((string) $ref['h']);
    }

    public function details(array $ref, string $url): array
    {
        $page = 'https://vimeo.com/'.($ref['id'] ?? '').(empty($ref['h']) ? '' : '/'.$ref['h']);
        // `width` asks for a frame that size instead of the 295px default.
        $oembed = $this->json('https://vimeo.com/api/oembed.json?width=1280&url='.rawurlencode($page));

        return array_filter([
            'title' => $oembed['title'] ?? null,
            'poster' => $oembed['thumbnail_url'] ?? null,
            'duration' => isset($oembed['duration']) ? (int) $oembed['duration'] : null,
        ]);
    }
}
