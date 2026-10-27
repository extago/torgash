<?php

namespace App\Core\Storefronts;

use App\Core\Support\SafeMode;
use App\Core\Themes\ThemeManager;
use Closure;
use Illuminate\Http\Request;

/**
 * Binds the current storefront (matched by host, falling back to the default
 * one), activates its theme and applies the visitor's locale.
 *
 * Which locale the URL asked for is read off the route that actually matched
 * (LocaleResolver::prefixOf) rather than decided here, so routing and
 * app()->getLocale() cannot disagree. Admin routes keep the default storefront
 * context; their own locale switcher (SetAdminLocale) runs after this and
 * overrides it.
 */
class ResolveStorefront
{
    public function __construct(protected ThemeManager $themes) {}

    public function handle(Request $request, Closure $next)
    {
        [$locale, $storefront] = LocaleResolver::forRequest($request);

        if ($storefront !== null) {
            // Every locale prefix in the route table exists for the whole
            // application, but a storefront only offers its own: /ru/... on a
            // storefront with no Russian is a URL that must not resolve at
            // all. Rendering it in the default locale instead would publish
            // the same page under two addresses.
            $urlLocale = LocaleResolver::prefixOf($request->route());

            if ($urlLocale !== null && ! in_array($urlLocale, $storefront->locales ?? [], true)) {
                abort(404);
            }

            app()->instance('torgash.storefront', $storefront);

            // Safe mode wears the engine's own theme whatever the storefront picked.
            $this->themes->setActive(SafeMode::active($request) ? SafeMode::THEME : ($storefront->theme ?: config('torgash.default_theme')));

            if ($locale !== null) {
                app()->setLocale($locale);
                $previous = $request->session()->get('locale');
                $request->session()->put('locale', $locale);

                // A signed-in customer's language, kept on the account.
                if ($redirect = CustomerLocale::handle($request, $storefront, $locale, is_string($previous) ? $previous : null)) {
                    return $redirect;
                }
            }
        }

        return $next($request);
    }
}
