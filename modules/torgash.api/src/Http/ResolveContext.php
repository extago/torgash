<?php

namespace Torgash\Api\Http;

use App\Models\Language;
use App\Models\Storefront;
use Closure;
use Illuminate\Http\Request;

/**
 * Which storefront and which language this call is about.
 *
 * An API request has no domain to resolve a storefront from — every call
 * arrives at the same host — so it says which one it means (`X-Storefront`, by
 * id or by code) and the shop's default answers when it does not. Prices,
 * currencies and translations all hang off that choice, and getting it wrong is
 * the kind of bug that surfaces a month later as "the exchange sent the wrong
 * prices".
 *
 * The language comes from `Accept-Language`, the header that exists for it.
 * Path prefixes (`/ru/…`) are the storefront's business: they exist so routing
 * and route caching work, and an API has neither problem.
 */
class ResolveContext
{
    public function handle(Request $request, Closure $next)
    {
        $asked = $request->header('X-Storefront') ?: $request->query('storefront');

        $storefront = $asked
            ? Storefront::query()->where('code', $asked)->orWhere('id', $asked)->first()
            : null;

        $storefront ??= Storefront::query()->orderByDesc('is_default')->orderBy('id')->first();

        if ($storefront) {
            app()->instance('torgash.storefront', $storefront);
            app()->setLocale($storefront->default_locale);
        }

        $wanted = strtolower(substr((string) $request->header('Accept-Language'), 0, 2));

        if ($wanted && Language::where('code', $wanted)->where('enabled', true)->exists()) {
            app()->setLocale($wanted);
        }

        return $next($request);
    }
}
