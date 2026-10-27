<?php

namespace Torgash\Guard\Blocking;

use Closure;
use Illuminate\Http\Request;
use Throwable;

/**
 * The door.
 *
 * Pushed onto the `web` group and nowhere else, which is rule one of PLAN
 * §25.2/4 expressed as a place rather than as a list: `/commerceml`, the
 * payment callbacks and `/api/v2/*` are all registered outside `web` because a
 * machine has no session — so this cannot reach them even by mistake, and no
 * future module has to remember to ask for an exemption.
 *
 * What it answers with is a 429 and a `Retry-After`, not a 403: a block here
 * always ends, and the honest status code says so. It is also deliberately not
 * a rendered page — booting the storefront theme to tell a scanner it is not
 * welcome is exactly the cost this is supposed to avoid, and the one visitor
 * who reaches it by mistake needs a sentence and a way to get in touch, not a
 * navigation menu.
 *
 * It never throws. A watchman that can 500 the shop is worse than no watchman:
 * the failure mode of everything else in this module is "nothing was recorded",
 * and it has to be that here too.
 */
class CheckBlock
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $refusal = $this->refusal($request);
        } catch (Throwable) {
            // See the class docblock. A broken guard lets the shop through.
            $refusal = null;
        }

        return $refusal ?? $next($request);
    }

    protected function refusal(Request $request)
    {
        $blocks = app(Blocks::class);

        if ($blocks->mode() === Blocks::OFF) {
            return null;
        }

        // Rule one, and first of all — before any lookup, so a signed-in
        // manager on a blocked office address does not even cost a query.
        if ($blocks->exempt($request)) {
            return null;
        }

        $block = $blocks->forRequest($request);

        if (! $block) {
            return null;
        }

        // Counted whether or not it is applied: in watch mode this number IS
        // the feature — it is the answer to "what would this have done to my
        // shop if I had switched it on".
        $blocks->seen($block);

        if (! $block->isEnforced()) {
            return null;
        }

        $seconds = $block->until ? (int) max(1, now()->diffInSeconds($block->until, false)) : 3600;
        $message = __('Too many requests from your address. Please try again later, or get in touch if you think this is a mistake.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 429, ['Retry-After' => $seconds]);
        }

        return response(
            '<!doctype html><meta charset="utf-8"><title>429</title>'
            .'<p style="font:16px system-ui;margin:3rem auto;max-width:32rem">'.e($message).'</p>',
            429,
            ['Retry-After' => $seconds, 'Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
