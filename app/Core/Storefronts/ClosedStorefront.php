<?php

namespace App\Core\Storefronts;

use App\Models\Storefront;
use Closure;
use Illuminate\Http\Request;

/**
 * A closed storefront answers a visitor with its notice and staff with the
 * shop (Storefront::isClosedFor()).
 *
 * On every route StorefrontRoutes registers and on nothing else, which is
 * what draws the line in the right place without a list of exceptions: the
 * admin and its sign-in, a payment gateway's callback, the 1C exchange, the
 * API and the module assets are not storefront pages, so a shop being filled
 * before opening keeps receiving its catalogue and its staff keep signing in.
 *
 * 503 with Retry-After, the answer a search engine reads as "come back
 * later" rather than "gone": closing a live shop for an hour of repairs must
 * not drop it from the index. `noindex` besides, for a shop that has never
 * been open.
 *
 * The preview link (`?preview=<token>` on any address) remembers its secret
 * in the session and sends the visitor on to the same address without it, so
 * the secret does not stay in the address bar to be copied further.
 */
class ClosedStorefront
{
    public function handle(Request $request, Closure $next)
    {
        $storefront = current_storefront();

        if (! $storefront instanceof Storefront || ! $storefront->closed) {
            return $next($request);
        }

        $offered = $request->query('preview');

        if (is_string($offered) && $storefront->closed_token && hash_equals($storefront->closed_token, $offered)) {
            $request->session()->put(Storefront::PREVIEW_SESSION.'.'.$storefront->getKey(), $offered);

            $rest = collect($request->query())->except('preview')->all();

            return redirect($request->url().($rest === [] ? '' : '?'.http_build_query($rest)));
        }

        if (! $storefront->isClosedFor($request)) {
            return $next($request);
        }

        return response()
            ->view('closed', ['storefront' => $storefront], 503)
            ->header('Retry-After', '3600')
            ->header('X-Robots-Tag', 'noindex');
    }
}
