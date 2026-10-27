<?php

namespace Torgash\Video\Sources;

/**
 * VK Video (vk.com, vkvideo.ru). A video is an owner and a number
 * (`video-22822305_456242110`); its embed code adds a `hash`, which VK asks
 * for on some videos and not on others, so it is kept whenever it was given.
 *
 * VK publishes no oEmbed, and its pages answer a robot with a sign-in
 * redirect more often than not: the title and poster are asked for, and a
 * video VK said nothing about is added without them — the admin uploads a
 * poster by hand.
 */
class VkVideo extends KnownPlatform
{
    protected const HOSTS = ['vk.com', 'vk.ru', 'vkvideo.ru'];

    public function key(): string
    {
        return 'vk';
    }

    public function platform(array $ref): string
    {
        return 'VK Video';
    }

    public function parse(string $url): ?array
    {
        if (! $this->owns($url)) {
            return null;
        }

        $query = $this->query($url);

        if (str_contains((string) parse_url($url, PHP_URL_PATH), 'video_ext.php')) {
            $oid = $query['oid'] ?? '';
            $id = $query['id'] ?? '';
        } elseif (preg_match('#video(-?\d+)_(\d+)#', urldecode((string) parse_url($url, PHP_URL_PATH).'?'.($query['z'] ?? '')), $m)) {
            [, $oid, $id] = $m;
        } else {
            return null;
        }

        if (! preg_match('/^-?\d+$/', $oid) || ! preg_match('/^\d+$/', $id)) {
            return null;
        }

        $hash = $query['hash'] ?? '';

        return array_filter(['oid' => $oid, 'id' => $id, 'hash' => preg_match('/^[0-9a-f]+$/i', $hash) ? $hash : null]);
    }

    public function playerUrl(array $ref): string
    {
        $query = array_filter([
            'oid' => $ref['oid'] ?? null,
            'id' => $ref['id'] ?? null,
            'hash' => $ref['hash'] ?? null,
            'autoplay' => 1,
        ], fn ($value) => $value !== null && $value !== '');

        return 'https://vkvideo.ru/video_ext.php?'.http_build_query($query);
    }

    public function details(array $ref, string $url): array
    {
        return $this->openGraph('https://vkvideo.ru/video'.($ref['oid'] ?? '').'_'.($ref['id'] ?? ''));
    }
}
