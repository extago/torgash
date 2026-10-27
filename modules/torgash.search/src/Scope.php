<?php

namespace Torgash\Search;

use Illuminate\Http\Request;

/**
 * Where a search looks: the CATALOGUE (products, their categories and
 * brands — the default, and all there is in a shop that does not search its
 * site) or the SITE: what other modules answer through
 * `search.suggest.sections` — information pages today, a blog tomorrow —
 * with the catalogue's answers beside them only if the shop says so
 * (`site_includes_catalog`).
 *
 * Searching the site is the shop's switch (`search_site`), and switching it
 * on is what puts the choice in the search box. One answer for the window,
 * the results page and the switch, so the three cannot disagree. The
 * visitor's choice rides the request (`?scope=`, radios inside the search
 * form, so it is submitted with the query and survives with no JavaScript)
 * and is remembered in the session for the rest of the visit.
 */
class Scope
{
    public const SITE = 'site';

    public const CATALOG = 'catalog';

    protected const SESSION = 'torgash.search.scope';

    public static function searchesSite(): bool
    {
        return (bool) setting('torgash.search', 'search_site');
    }

    /** Whether the visitor is offered the switch — exactly when the site is searched. */
    public static function switchable(): bool
    {
        return self::searchesSite();
    }

    /** Does a search of this scope answer with products? */
    public static function withCatalog(string $scope): bool
    {
        return $scope === self::CATALOG || (bool) setting('torgash.search', 'site_includes_catalog');
    }

    public static function current(?Request $request = null): string
    {
        if (! self::searchesSite()) {
            return self::CATALOG;
        }

        $request ??= request();
        $asked = (string) $request->query('scope', '');

        if (in_array($asked, [self::SITE, self::CATALOG], true)) {
            if ($request->hasSession()) {
                $request->session()->put(self::SESSION, $asked);
            }

            return $asked;
        }

        $remembered = $request->hasSession() ? $request->session()->get(self::SESSION) : null;

        if (in_array($remembered, [self::SITE, self::CATALOG], true)) {
            return $remembered;
        }

        return setting('torgash.search', 'default_scope') === self::SITE ? self::SITE : self::CATALOG;
    }
}
