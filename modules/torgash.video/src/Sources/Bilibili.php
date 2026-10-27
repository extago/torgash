<?php

namespace Torgash\Video\Sources;

/**
 * Bilibili. A video is a `BV…` id (or, in old links, `av` and a number).
 * Short b23.tv links are a redirect the engine does not follow — the full
 * address is one click away in the browser.
 *
 * Its API refuses many servers outside China; a refusal means no poster,
 * never no video.
 */
class Bilibili extends KnownPlatform
{
    protected const HOSTS = ['bilibili.com'];

    public function key(): string
    {
        return 'bilibili';
    }

    public function platform(array $ref): string
    {
        return 'Bilibili';
    }

    public function parse(string $url): ?array
    {
        if (! $this->owns($url)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = $this->query($url);

        $bvid = preg_match('#/video/(BV[0-9A-Za-z]{10})#', $path, $m) ? $m[1] : ($query['bvid'] ?? '');

        if (preg_match('/^BV[0-9A-Za-z]{10}$/', $bvid)) {
            return ['bvid' => $bvid];
        }

        $aid = preg_match('#/video/av(\d+)#i', $path, $m) ? $m[1] : ($query['aid'] ?? '');

        return preg_match('/^\d+$/', $aid) ? ['aid' => $aid] : null;
    }

    public function playerUrl(array $ref): string
    {
        $id = isset($ref['bvid']) ? ['bvid' => $ref['bvid']] : ['aid' => $ref['aid'] ?? ''];

        return 'https://player.bilibili.com/player.html?'.http_build_query($id + ['autoplay' => 1, 'high_quality' => 1, 'isOutside' => 'true']);
    }

    public function details(array $ref, string $url): array
    {
        $id = isset($ref['bvid']) ? 'bvid='.rawurlencode((string) $ref['bvid']) : 'aid='.rawurlencode((string) ($ref['aid'] ?? ''));
        $data = $this->json('https://api.bilibili.com/x/web-interface/view?'.$id, [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'Referer' => 'https://www.bilibili.com/',
        ])['data'] ?? null;

        return is_array($data) ? array_filter([
            'title' => $data['title'] ?? null,
            // The API writes the picture without a scheme now and then.
            'poster' => isset($data['pic']) ? preg_replace('#^(//|http://)#', 'https://', (string) $data['pic']) : null,
            'duration' => isset($data['duration']) ? (int) $data['duration'] : null,
        ]) : [];
    }
}
