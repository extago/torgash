<?php

namespace App\Core\Storefronts;

use Closure;
use Illuminate\Support\Facades\Route;

/**
 * Registers a module's storefront-facing routes.php in every locale shape at
 * once: the bare one (the storefront's default locale — /product/foo) and one
 * prefixed copy per non-default locale (/ru/product/foo).
 *
 * The prefixed copy carries a name prefix to match its URL prefix, so
 * "catalog.product" and "ru.catalog.product" are two distinct named routes
 * pointing at the same controller. Nothing in a view has to know that:
 * LocaleUrlGenerator rewrites route() lookups to the shape the current
 * request came in as, so route('catalog.product', $slug) keeps producing
 * /product/foo on an English page and /ru/product/foo on a Russian one.
 *
 * Registering both shapes up front is what makes the route table independent
 * of the request being served — see LocaleResolver's docblock for why that
 * matters. Admin routes never go through this; they keep their own separate
 * locale switcher.
 */
class StorefrontRoutes
{
    /**
     * @param  string|Closure  $routes  A routes file (what modules pass) or a
     *         closure, for callers that need to wrap the file in a prefix of
     *         their own — nested inside this group, so the two prefixes
     *         concatenate instead of the inner one replacing the outer.
     */
    public static function group(string|Closure $routes): void
    {
        // ClosedStorefront after `web`, whose ResolveStorefront has bound the
        // storefront it asks about.
        Route::middleware(['web', ClosedStorefront::class])->group($routes);

        foreach (LocaleResolver::routePrefixes() as $locale) {
            Route::middleware(['web', ClosedStorefront::class])->prefix($locale)->name($locale.'.')->group($routes);
        }
    }
}
