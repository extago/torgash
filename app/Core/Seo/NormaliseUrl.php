<?php

namespace App\Core\Seo;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One page, one address — enforced with a 301 rather than hoped for.
 *
 * `/catalog/`, `//catalog` and `/Catalog` are the same page to a shop and three
 * pages to a crawler, and every one of them is reachable today: Laravel's
 * router trims the trailing slash itself and matches the rest, so the duplicate
 * answers 200 and nobody notices. A canonical tag would cover it, but a 301 is
 * the stronger statement and it also fixes the link that was pasted wrong.
 *
 * NOT stored rows in the redirect table: those record a rename, which is a fact
 * about the past that has to survive. This is a rule about shape, computed the
 * same way for every address, and a table of it would be infinite.
 *
 * Off by default, and each part switchable on its own. Lower-casing in
 * particular is a decision, not a fix: a shop whose slugs were imported with
 * capitals in them would 301 every one of its own live URLs to a 404. So the
 * three switches, and the redirect only ever fires when the result differs.
 *
 * GET and HEAD only, and never under /admin or /api: a 301 on a POST loses the
 * body, and a machine's address is not the shop's to tidy.
 *
 * A GLOBAL middleware rather than one in the `web` group, because the group
 * runs only once a route has matched, and `//catalog` or `/Catalog` matches
 * nothing at all — precisely the address that needs the redirect. The price is
 * that the storefront is not resolved yet, so these three switches are read
 * shop-wide rather than per storefront, which is right: the shape of an address
 * is the engine's rule, not a shopfront's decoration.
 */
class NormaliseUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($target = $this->legacyAddress($request)) {
            return redirect($target, 301);
        }

        if ($target = $this->target($request)) {
            return redirect($target, 301);
        }

        return $next($request);
    }

    /**
     * The one redirect that cannot wait for a 404: the old shop's front
     * controller.
     *
     * `/index.php?dispatch=products.view&product_id=12` arrives with its front
     * controller already stripped, so PHP sees the path `/` — which the home
     * page answers with a cheerful 200, and nothing ever 404s for the exception
     * handler to catch. A shop moving from another engine has thousands of such
     * addresses in its file, and they are the ones with links pointing at them.
     *
     * Asked only when the address has a query string AND no path of its own, so
     * an ordinary page costs no query at all, and the homepage costs one only
     * when somebody arrives with `?utm_source=…` on it.
     */
    protected function legacyAddress(Request $request): ?string
    {
        if (! $request->isMethodSafe() || $request->getQueryString() === null) {
            return null;
        }

        if (trim($request->getPathInfo(), '/') !== '') {
            return null;
        }

        return app(RedirectManager::class)->redirectFor($request);
    }

    /** The address this request should have used, or null when it is already right. */
    protected function target(Request $request): ?string
    {
        if (! $request->isMethodSafe() || $request->is('admin', 'admin/*', 'api/*')) {
            return null;
        }

        $original = $request->getRequestUri();
        $path = (string) parse_url($original, PHP_URL_PATH);
        $query = (string) parse_url($original, PHP_URL_QUERY);
        $normalised = $path;

        if (setting('core', 'url_collapse_slashes', false)) {
            $normalised = (string) preg_replace('#/{2,}#', '/', $normalised);
        }

        if (setting('core', 'url_strip_trailing_slash', false) && $normalised !== '/') {
            $normalised = rtrim($normalised, '/');
            $normalised = $normalised === '' ? '/' : $normalised;
        }

        if (setting('core', 'url_lowercase', false)) {
            $normalised = mb_strtolower($normalised);
        }

        if ($normalised === $path) {
            return null;
        }

        // Built from the request's own host, not from url(): this runs before
        // the storefront is resolved, and a shop with several domains would
        // otherwise send every visitor to whichever one APP_URL names.
        return $request->getSchemeAndHttpHost().$normalised.($query === '' ? '' : '?'.$query);
    }
}
