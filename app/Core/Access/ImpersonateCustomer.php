<?php

namespace App\Core\Access;

use Closure;
use Illuminate\Http\Request;

/**
 * The storefront, for a manager who pressed the button on a customer's card
 * ({@see Impersonation}).
 *
 * On every route StorefrontRoutes registers and on the pages at root
 * addresses, and on nothing else: the admin is not in that list, which is what
 * keeps the manager themselves in the next tab. After `web` and the closed
 * storefront's check, because those answer about the real person: their second
 * factor, their right to see a shop not yet open, their language.
 */
class ImpersonateCustomer
{
    public function handle(Request $request, Closure $next)
    {
        // All an ordinary visitor ever pays for this.
        if (! $request->session()->has(Impersonation::SESSION)) {
            return $next($request);
        }

        $impersonation = app(Impersonation::class);

        if (! $impersonation->enter($request)) {
            return $next($request);
        }

        // "Sign out" in the customer's menu: what the manager means is leave.
        // Signing out would end their own session, the admin's included.
        if ($impersonation->routeName($request) === 'account.logout') {
            return $impersonation->leave($request);
        }

        return $impersonation->refuses($request) ? $this->refused($request) : $next($request);
    }

    /**
     * Said by the strip on the next page, which is the one thing every page
     * has. HTMX does not swap an error, so it is told to reload.
     */
    protected function refused(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => __('Only the customer can do this.')], 403);
        }

        $request->session()->flash(Impersonation::REFUSED, true);

        if ($request->hasHeader('HX-Request')) {
            return response('', 200)->header('HX-Refresh', 'true');
        }

        return redirect()->back(fallback: route('account.dashboard'));
    }
}
