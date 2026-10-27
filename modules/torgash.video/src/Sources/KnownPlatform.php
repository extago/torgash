<?php

namespace Torgash\Video\Sources;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What the platforms the engine knows by name have in common: a list of
 * hosts, no sandbox, and asking the platform about a video without ever
 * letting its silence stop the video being added.
 */
abstract class KnownPlatform implements VideoSource
{
    /** @var list<string> hosts this platform answers on, subdomains included */
    protected const HOSTS = [];

    public function sandboxed(): bool
    {
        return false;
    }

    /** Is the address on one of this platform's hosts? */
    protected function owns(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (static::HOSTS as $known) {
            if ($host === $known || str_ends_with($host, '.'.$known)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> the address's query, read the plain way */
    protected function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return array_map('strval', array_filter($query, 'is_scalar'));
    }

    /** A JSON answer, or null for anything else — a timeout, a refusal, a page of HTML. */
    protected function json(string $url, array $headers = []): ?array
    {
        try {
            $response = Http::timeout(8)->withHeaders($headers + ['Accept' => 'application/json'])->get($url);

            return $response->successful() && is_array($response->json()) ? $response->json() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The page's Open Graph title and picture — what every platform hands to
     * a link preview, and so the last thing one stops publishing.
     *
     * @return array{title?: string, poster?: string}
     */
    protected function openGraph(string $url): array
    {
        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; TorgashBot/1.0)', 'Accept' => 'text/html'])
                ->get($url);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $html = substr($response->body(), 0, 300_000);
        $found = [];

        foreach (['title' => 'og:title', 'poster' => 'og:image'] as $key => $property) {
            if (preg_match('#<meta[^>]+property=["\']'.preg_quote($property, '#').'["\'][^>]+content=["\']([^"\']+)#i', $html, $m)
                || preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']'.preg_quote($property, '#').'["\']#i', $html, $m)) {
                $found[$key] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
            }
        }

        return $found;
    }
}
