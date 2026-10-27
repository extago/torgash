<?php

namespace Torgash\Video\Sources;

/**
 * Embed code from a site the engine does not know by name — a player the
 * shop pasted from rutube.ru, a conference service, its own video host.
 *
 * Only the `src` of the code is kept, and only from a host the shop listed
 * (`allowed_hosts`). The right to edit a product is not the right to put a
 * script on the storefront — that is what "Code on pages" is for — and the
 * same address can arrive from an import nobody read. The engine draws the
 * <iframe> itself, in a sandbox, so whatever else the pasted code said is
 * never printed.
 */
class Embed implements VideoSource
{
    public function key(): string
    {
        return 'embed';
    }

    public function platform(array $ref): string
    {
        return preg_replace('/^www\./', '', (string) parse_url((string) ($ref['src'] ?? ''), PHP_URL_HOST));
    }

    public function parse(string $url): ?array
    {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return null;
        }

        return $this->allows((string) parse_url($url, PHP_URL_HOST)) ? ['src' => $url] : null;
    }

    public function playerUrl(array $ref): string
    {
        return (string) ($ref['src'] ?? '');
    }

    public function details(array $ref, string $url): array
    {
        return [];
    }

    public function sandboxed(): bool
    {
        return true;
    }

    /**
     * Listed, subdomains included — and never the shop's own host: a sandbox
     * that allows scripts AND the same origin is no sandbox at all for a page
     * from that origin.
     */
    public function allows(string $host): bool
    {
        $host = strtolower($host);

        if ($host === '' || $host === strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))) {
            return false;
        }

        foreach (self::allowedHosts() as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function allowedHosts(): array
    {
        $lines = preg_split('/[\s,]+/', strtolower((string) setting('torgash.video', 'allowed_hosts')));

        return array_values(array_filter(array_map(
            fn ($line) => preg_replace(['#^[a-z]+://#', '#^www\.#', '#/.*$#'], '', trim($line)),
            $lines ?: [],
        )));
    }
}
