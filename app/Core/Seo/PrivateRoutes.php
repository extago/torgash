<?php

namespace App\Core\Seo;

use App\Core\Storefronts\LocaleResolver;
use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The pages that are not for an index — named once, said in two places.
 *
 * A cart, a checkout, an account and a search result are the same fact stated
 * to two different readers: `<meta name="robots">` on the page itself and a
 * `Disallow:` line in robots.txt. Before this they were two lists — a module
 * added `/cart` to `core.robots.disallow` and nothing put a meta tag on the
 * cart page at all — and two lists about one thing are two lists that drift.
 *
 * A module names ROUTE NAMES, not paths (`cart.index`, `checkout.*`), because
 * a path is something it only half knows: StorefrontRoutes registers every
 * storefront route once per locale, so `/cart` is also `/ru/cart`, and a module
 * spelling that out is a module that learned core's URL scheme. Names come back
 * through the `core.seo.private_routes` filter, asked lazily — provider boot
 * order is not ours to depend on.
 *
 * What is left in `core.robots.disallow` is what no route expresses: `/admin`
 * is a prefix under which routes are registered but which a crawler must be
 * refused wholesale, uploads and vendor paths likewise.
 */
class PrivateRoutes
{
    /** @var list<string>|null */
    protected ?array $names = null;

    /** @var list<string>|null */
    protected ?array $paths = null;

    /**
     * Patterns, `*` allowed — core's own plus whatever modules add.
     *
     * Core names the account pages because core owns them. Everything else is
     * its module's to declare, and disappears with it.
     *
     * @return list<string>
     */
    public function names(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }

        $names = hook_filter('core.seo.private_routes', ['account.*'], []);

        return $this->names = array_values(array_unique(array_filter((array) $names)));
    }

    /**
     * Is this route one of them?
     *
     * The locale prefix comes off the name first: `ru.cart.index` is the same
     * page as `cart.index`, and a module must not have to say so twice.
     */
    public function matches(?RouteObject $route): bool
    {
        $name = $route?->getName();

        if ($name === null) {
            return false;
        }

        $candidates = [$name];

        if ($prefix = LocaleResolver::prefixOf($route)) {
            $candidates[] = substr($name, strlen($prefix) + 1);
        }

        foreach ($candidates as $candidate) {
            if (Str::is($this->names(), $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same list as paths, for robots.txt — every locale shape included,
     * because the route table already holds them all.
     *
     * A parameterised URI is cut at its first parameter (`/checkout/{order}/…`
     * becomes `/checkout/`), which is the only honest translation: a
     * `Disallow:` line is a prefix, and a crawler cannot be handed a pattern
     * with a placeholder in it. Prefixes that already cover another line win,
     * so `/checkout` alone is printed rather than it and four of its children.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        if ($this->paths !== null) {
            return $this->paths;
        }

        $paths = [];

        foreach (Route::getRoutes() as $route) {
            if (! $this->matches($route) || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            if (($cut = strpos($uri, '{')) !== false) {
                $uri = substr($uri, 0, $cut);
            }

            $uri = '/'.trim($uri, '/');

            if ($uri !== '/') {
                $paths[$uri] = true;
            }
        }

        $paths = array_keys($paths);
        sort($paths);

        $kept = [];

        foreach ($paths as $path) {
            foreach ($kept as $seen) {
                if (str_starts_with($path.'/', $seen.'/')) {
                    continue 2;
                }
            }

            $kept[] = $path;
        }

        return $this->paths = $kept;
    }
}
