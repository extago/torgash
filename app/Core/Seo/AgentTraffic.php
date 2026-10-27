<?php

namespace App\Core\Seo;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * The `torgash-agent` limit, applied from inside a controller.
 *
 * For the one shape of route that cannot carry route middleware: a FALLBACK.
 * A page at a root address is answered by `Route::fallback`, which exists
 * precisely because it is tried after everything else — a real route matching
 * `{path}.md` would have to be registered before it and would then depend on
 * module boot order to not swallow another module's addresses.
 *
 * So the same limiter is invoked by hand rather than a second rule being
 * written beside it: this is Laravel's own throttle middleware, called
 * directly, which is what keeps the 429 and its `Retry-After` identical to the
 * routed half.
 */
class AgentTraffic
{
    public static function enforce(Request $request): void
    {
        app(ThrottleRequests::class)->handle($request, fn () => response(''), 'torgash-agent');
    }
}
