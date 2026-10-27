<?php

namespace App\Core\Http;

use Closure;
use Illuminate\Http\Request;

/**
 * APP_DEBUG shows its pages only to the machine the shop runs on.
 *
 * A debug page prints the code, the query that failed with its values and
 * the environment around it. Left on by mistake — or on a dev box opened to
 * the internet through a tunnel, which is how the audit of 2026-10-15 found
 * it — every visitor who can make an error gets all of that. So the switch
 * is honoured only for a request that is local in both senses: addressed to a
 * development host, and not carried in by a proxy or a tunnel. A tunnel can
 * rewrite Host to `torgash.local`, but it cannot help announcing who the
 * visitor really was. Anything else is answered as a live shop would be.
 *
 * Per request and put back afterwards, so a persistent worker serving the
 * next local request still sees the switch as configured.
 */
class DebugOnlyLocally
{
    /** Headers a proxy or tunnel adds; a browser on this machine sends none of them. */
    public const PROXY_HEADERS = ['X-Forwarded-For', 'Forwarded', 'CF-Connecting-IP', 'CF-Ray', 'True-Client-IP', 'X-Real-IP'];

    public function handle(Request $request, Closure $next)
    {
        if (! config('app.debug') || static::isLocal($request)) {
            return $next($request);
        }

        config(['app.debug' => false]);

        try {
            return $next($request);
        } finally {
            config(['app.debug' => true]);
        }
    }

    public static function isLocal(Request $request): bool
    {
        foreach (self::PROXY_HEADERS as $header) {
            if ($request->headers->has($header)) {
                return false;
            }
        }

        return SecurityHeaders::developmentHost($request->getHost());
    }
}
