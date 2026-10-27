<?php

namespace Torgash\Checkout\Orders;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Torgash\Checkout\Models\Order;

/**
 * The customer's key to one order.
 *
 * A key at all, because the order number is sequential: TG-000124 is one
 * keystroke from TG-000123, so the number can never be what authorises reading.
 * And a key that belongs to the ORDER rather than to an address, which is the
 * part Laravel's own signed URLs cannot do here: they hash the WHOLE address,
 * and every storefront page exists once per locale (`/checkout/…` and
 * `/ru/checkout/…`, two route names for one page). A signature made for one
 * shape is invalid on the other, so the language switcher — which carries the
 * query string across, as it must for filters and paging — turned a working
 * link into a 404 the moment the customer changed language. Found the honest
 * way: by opening the /ru/ copy of a link that worked.
 *
 * So the key is an HMAC over a canonical string (this route, this order
 * number), and the same string opens the order in every language. Security is
 * the same shape as a signed URL — an HMAC under the app key, over exactly one
 * order — minus the two features this deliberately does not want: expiry (an
 * order is a thing people come back to months later) and URL binding (which is
 * the bug).
 *
 * Links are BUILT here too: url() puts the key on whichever locale's path the
 * reader should get, so a Russian customer's email points at the Russian page.
 */
class OrderKey
{
    /**
     * The signature that belongs to this order on this route, whatever the
     * locale — an HMAC over a canonical string rather than over a URL.
     *
     * Not URL::signedRoute(): the url generator here is locale-aware and
     * rewrites a route name into the shape the CURRENT request came in as, so
     * asking it from inside a /ru/ request produced the Russian URL and
     * therefore a different signature — the very thing this class exists to
     * stop. Measured, by opening the /ru/ copy of a link that worked.
     *
     * Nothing is lost by signing a string of our own: expiry and URL binding
     * are what Laravel's signer adds, and this key deliberately has neither —
     * it is one order, for ever.
     */
    public static function signature(Order $order, string $route = 'checkout.confirmation'): string
    {
        return hash_hmac(
            'sha256',
            'order-key:'.$route.':'.$order->order_number,
            (string) config('app.key'),
        );
    }

    /**
     * The link to hand the customer.
     *
     * @param  string|null  $locale  the shape to build; null means the reader's
     *                               current one, which is what a page wants — an email passes the language
     *                               the order was placed in, because a queued mailable has no request to
     *                               take it from.
     */
    public static function url(Order $order, string $route = 'checkout.confirmation', ?string $locale = null): string
    {
        $name = $locale && Route::has($locale.'.'.$route) ? $locale.'.'.$route : $route;

        return route($name, $order->order_number).'?signature='.self::signature($order, $route);
    }

    /** Does this request carry the order's key? */
    public static function matches(Request $request, Order $order, string $route = 'checkout.confirmation'): bool
    {
        $given = (string) $request->query('signature', '');

        return $given !== '' && hash_equals(self::signature($order, $route), $given);
    }
}
