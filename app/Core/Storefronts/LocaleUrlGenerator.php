<?php

namespace App\Core\Storefronts;

use Illuminate\Routing\UrlGenerator;

/**
 * Keeps generated links in the locale shape the current request arrived in.
 *
 * StorefrontRoutes registers every storefront route twice — "catalog.product"
 * for the default locale and "ru.catalog.product" for the /ru copy — so a
 * plain route('catalog.product', …) would always produce the default-locale
 * URL and quietly throw a Russian visitor back to English on the first link
 * they clicked.
 *
 * This looks at which route matched (its name carries the prefix) and, when
 * that is a locale copy, prefers the same-locale variant of whatever name is
 * being generated, falling back to the bare name when no such variant exists
 * — which is what makes links to non-localised routes (admin, /account, asset
 * URLs) keep working from a localised page.
 *
 * Deriving the prefix from the live request rather than being told it by
 * middleware is deliberate: there is no state to reset between requests, so a
 * long-lived worker cannot leak one visitor's locale into the next one's links.
 */
class LocaleUrlGenerator extends UrlGenerator
{
    /** @param  string  $name */
    public function route($name, $parameters = [], $absolute = true)
    {
        if (is_string($name) && ($prefix = LocaleResolver::prefixOf($this->request?->route()))) {
            $localized = $prefix.'.'.$name;

            if ($this->routes->hasNamedRoute($localized)) {
                $name = $localized;
            }
        }

        return parent::route($name, $parameters, $absolute);
    }
}
