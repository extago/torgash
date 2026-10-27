<?php

namespace Torgash\Video\Sources;

/**
 * YouTube, played from youtube-nocookie.com — the address YouTube itself
 * offers for a player that sets no cookie until it is played, which is the
 * only moment it loads here anyway.
 */
class YouTube extends KnownPlatform
{
    protected const HOSTS = ['youtube.com', 'youtu.be', 'youtube-nocookie.com'];

    public function key(): string
    {
        return 'youtube';
    }

    public function platform(array $ref): string
    {
        return 'YouTube';
    }

    public function parse(string $url): ?array
    {
        if (! $this->owns($url)) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        $id = match (true) {
            str_ends_with($host, 'youtu.be') => trim($path, '/'),
            (bool) preg_match('#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $m) => $m[1],
            default => $this->query($url)['v'] ?? '',
        };

        return preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? ['id' => $id] : null;
    }

    public function playerUrl(array $ref): string
    {
        return 'https://www.youtube-nocookie.com/embed/'.rawurlencode((string) ($ref['id'] ?? '')).'?autoplay=1&rel=0';
    }

    public function details(array $ref, string $url): array
    {
        $id = (string) ($ref['id'] ?? '');
        $oembed = $this->json('https://www.youtube.com/oembed?format=json&url='.rawurlencode('https://www.youtube.com/watch?v='.$id));

        return array_filter([
            'title' => $oembed['title'] ?? null,
            // The full-size frame exists only for videos uploaded in HD; the
            // smaller one always does. The first that downloads is kept.
            'poster' => ["https://i.ytimg.com/vi/{$id}/maxresdefault.jpg", "https://i.ytimg.com/vi/{$id}/hqdefault.jpg"],
        ]);
    }
}
