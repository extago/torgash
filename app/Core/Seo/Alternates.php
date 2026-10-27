<?php

namespace App\Core\Seo;

use App\Core\Storefronts\LocaleResolver;

/**
 * The same address in the shop's other languages.
 *
 * One place that knows the rule — no prefix for the storefront's default
 * locale, `/xx/` for every other one — because three things now need it and
 * they must not disagree: the `hreflang` links in a page's head, the same links
 * in the sitemap, and anything that comes next. A page and a sitemap that
 * advertise different translations of one address are worse than neither.
 *
 * It does NOT check that the other language's page exists; the caller knows
 * whether it does. PageMeta asks the route table (a page may exist in one
 * language only); the sitemap does not have to, because every storefront route
 * is registered in every locale shape at once (StorefrontRoutes).
 */
class Alternates
{
    /**
     * @param  string  $url  an absolute URL of this shop
     * @return array<string, string> locale => URL, empty for a single-language shop
     */
    public static function forUrl(string $url, ?array $locales = null, ?string $default = null): array
    {
        $storefront = current_storefront();
        $locales ??= $storefront?->locales ?? [];
        $default ??= $storefront?->default_locale;

        if (count($locales) < 2) {
            return [];
        }

        $parts = parse_url($url);
        $path = trim($parts['path'] ?? '/', '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $path = self::stripPrefix($path);

        $alternates = [];

        foreach ($locales as $locale) {
            $alternates[$locale] = self::build($locale === $default ? '' : $locale.'/', $path).$query;
        }

        return $alternates;
    }

    /** The path with whatever locale segment it carries taken off. */
    public static function stripPrefix(string $path): string
    {
        foreach (LocaleResolver::routePrefixes() as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return ltrim(substr($path, strlen($prefix)), '/');
            }
        }

        return $path;
    }

    /**
     * The root keeps its slash, exactly as a canonical does: an alternate that
     * differs from the address it points at by one character is a third URL for
     * the same page.
     */
    protected static function build(string $prefix, string $path): string
    {
        $suffix = $prefix.$path;

        return $suffix === '' ? rtrim(url('/'), '/').'/' : url($suffix);
    }
}
