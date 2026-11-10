<?php

namespace App\Core\Http;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Laravel's `throttle:N,M` counts per visitor, not per route: every door using
 * the plain form added to ONE counter. Five reports from a browser about a
 * blocked script (`/csp-report`) closed "I lost my password" for everyone on
 * that address, and the ten-minute window of the tightest limit lasted as long
 * as the window of whichever route was hit first.
 *
 * The door goes into the key. By action rather than address, so the copies of
 * a page in each language are one door.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $visitor = parent::resolveRequestSignature($request);
        $route = $request->route();
        $door = $route->getActionName() === 'Closure' ? $route->uri() : $route->getActionName();

        return sha1($visitor.'|'.$door);
    }
}
