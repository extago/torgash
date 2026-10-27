<?php

namespace App\Core\Storefronts;

use App\Models\Storefront;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Throwable;

/**
 * Single source of truth for "which locales exist in URLs" and "what locale is
 * this request in".
 *
 * These are two different questions and used to be one. Route registration
 * asked the first but was answered with the second: the storefront route file
 * was registered with, or without, a /{locale} prefix depending on the URL of
 * the request being served. It worked, but it made the shape of the route
 * table a function of the incoming request, which rules out anything that
 * boots the application once and serves many requests from it — route:cache
 * and every persistent-worker setup (FrankenPHP worker mode, Octane, php-fpm
 * with a resident app). The first request's locale would freeze the route
 * table for every request after it.
 *
 * Now both shapes are registered up front (see StorefrontRoutes): the bare one
 * and one prefixed group per locale that is non-default somewhere. Which of
 * them matched is a property of the URL, and generating links in the matching
 * shape is a URL-generation concern, handled per request by LocaleUrlGenerator.
 *
 * The storefront's default locale is never prefixed (/product/foo); every
 * other enabled locale always is (/ru/product/foo). That is a deliberate SEO
 * choice — one canonical URL per page — and the reason a single route pattern
 * with an optional {locale?} segment cannot express this (Laravel cannot
 * compile one: {locale?}/catalog 404s on a bare /catalog, verified by hand).
 */
class LocaleResolver
{
    /** @var array<int, string>|null */
    protected static ?array $routePrefixes = null;

    /**
     * Locales that get their own prefixed copy of the storefront routes: every
     * locale that is non-default for at least one enabled storefront.
     *
     * Read once per PROCESS, not per request, because it decides the route
     * table, which is itself built once per process. The practical consequence
     * is worth knowing: adding a locale to a storefront takes effect on the
     * next boot (immediately today, since each request boots; after a restart
     * once workers are in play) — exactly like any other change to routes.
     *
     * @return array<int, string>
     */
    public static function routePrefixes(): array
    {
        if (static::$routePrefixes !== null) {
            return static::$routePrefixes;
        }

        try {
            $prefixes = Storefront::query()->where('enabled', true)->get()
                ->flatMap(fn (Storefront $s) => array_diff($s->locales ?? [], [$s->default_locale]))
                ->unique()->values()->all();
        } catch (Throwable) {
            $prefixes = []; // pre-migration state: no storefronts table yet
        }

        return static::$routePrefixes = $prefixes;
    }

    /**
     * The locale prefix the given route was registered under, or null for the
     * bare (default-locale) shape.
     *
     * Read off the ROUTE NAME ("ru.catalog.product"), not off the first URL
     * segment: the name is what StorefrontRoutes actually stamped on the
     * prefixed copy, so it cannot be confused by a category slug that happens
     * to read like a locale code.
     */
    public static function prefixOf(?Route $route): ?string
    {
        $name = $route?->getName();

        if ($name === null || ! str_contains($name, '.')) {
            return null;
        }

        $candidate = strstr($name, '.', true);

        return in_array($candidate, static::routePrefixes(), true) ? $candidate : null;
    }

    /**
     * [locale, storefront] for this request — the storefront matched by host
     * (falling back to the default one), and the locale its URL asked for,
     * defaulting to that storefront's own default. Both null before the
     * storefronts table exists.
     *
     * Does NOT reject a locale the storefront doesn't offer; that check needs
     * to produce a 404 and so belongs to the middleware, which can.
     *
     * @return array{0: ?string, 1: ?Storefront}
     */
    public static function forRequest(Request $request): array
    {
        try {
            $storefront = Storefront::resolveByHost($request->getHost());
        } catch (Throwable) {
            return [null, null]; // pre-migration state
        }

        if ($storefront === null) {
            return [null, null];
        }

        $locales = $storefront->locales ?? [];
        $locale = static::prefixOf($request->route()) ?? $storefront->default_locale;

        return [in_array($locale, $locales, true) ? $locale : null, $storefront];
    }
}
