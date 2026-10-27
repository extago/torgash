<?php

namespace Torgash\Guard\TwoFactor;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * The door, and — exactly like `Blocking\CheckBlock` — it is a PLACE rather
 * than a list of paths.
 *
 * Appended to the `web` group and nowhere else. Everything credentialed in this
 * engine is deliberately registered outside `web` — `/api/v2/*` on a bearer
 * token, `/commerceml` on basic auth, the payment gateways' callbacks, all of
 * them machines with no session — so none of them is reached by this, and none
 * of them is weakened by it either: a session cookie is not a credential any of
 * them accepts. What is left is the shop's own HTML, and every page of it goes
 * through here.
 *
 * ## Why the check is here and not inside the login form
 *
 * The tempting design is to intercept `Auth::attempt`, keep the person half
 * logged in and hand them a code box. It is also the wrong one for this engine:
 * there are two login controllers in core today, a module could add a third
 * (a social login, "sign in as this customer" for support), and each of them
 * would have to remember. Standing in the `web` group instead means the rule is
 * stated once and applies to every way there is of becoming logged in,
 * including the ones written after it — a remembered session picked up by the
 * cookie next month gets asked again, because the answer lives in the session
 * and that session is new.
 *
 * The price, said plainly: between the password and the code the session IS
 * authenticated. So this lets through exactly four things and refuses the whole
 * rest of the shop — the code box, the way out, the enrolment page when the
 * shop is insisting on one, and a module's static asset. Nothing else in `web`
 * is served, and nothing outside `web` takes a session, so what that half-open
 * session can reach is the page asking it for the code.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        return $this->stop($request, $user) ?? $next($request);
    }

    /**
     * Deliberately NOT wrapped in a try/catch, which is the opposite of every
     * other part of this module.
     *
     * A watchman that can 500 the shop is worse than no watchman — but that
     * argument belongs to the parts that WATCH. This one is a lock, and a lock
     * that opens when it is confused is not a lock. It costs one cached
     * settings read and one indexed lookup; if either of those throws, the
     * shop's database is gone and the storefront is not up either.
     */
    protected function stop(Request $request, User $user)
    {
        $requirement = app(Requirement::class);

        if (! $requirement->offered($user)) {
            return null;
        }

        // Armed: the only question is whether THIS session has answered it.
        if (app(Enrolment::class)->active($user)) {
            return app(Stamp::class)->holds($user)
                ? null
                : $this->send($request, 'guard.two_factor', $user);
        }

        // Not armed. Offered-but-not-taken-up is a perfectly good answer and
        // the person carries on; being required and not having one is the
        // enrolment they were always going to have to do, brought forward.
        if (! $requirement->required($user)) {
            return null;
        }

        return $this->send($request, $this->setupRoute($user), $user);
    }

    /**
     * Where a person who is being made to enrol is sent — their own security
     * page, in whichever half of the shop they belong to. A manager is not sent
     * to the storefront's account area, and a customer is not sent to the
     * admin, where they would be answered 403 in a loop.
     */
    protected function setupRoute(User $user): string
    {
        return app(Requirement::class)->isStaff($user)
            ? 'admin.guard.security'
            : 'account.security';
    }

    /**
     * Send them there — unless they are already there, or on their way out.
     *
     * The names are matched with a leading star because every storefront route
     * exists once per locale (`ru.account.security`), which is the same reason
     * the account menu matches its own item that way.
     */
    protected function send(Request $request, string $route, User $user)
    {
        $allowed = [
            // The code box itself, and its POST.
            '*guard.two_factor', '*guard.two_factor.verify',
            // The way out. Somebody who cannot answer must always be able to
            // stop being logged in — without it the only way out of a lost
            // phone is clearing cookies by hand.
            '*logout',
            // A module's own js/css. Static bytes, no session read, and the
            // page below may well want one.
            'module.asset',
            // ...plus wherever we are sending them, or this is a loop.
            '*'.$route,
        ];

        // The enrolment screen's own POSTs (start, confirm) share its name
        // prefix. Only opened when enrolment is where they are being sent: a
        // person who HAS a second factor and has not proved it must not reach
        // the page that can switch it off.
        if (str_ends_with($route, 'security')) {
            $allowed[] = '*'.$route.'.*';
        }

        if ($request->routeIs(...$allowed)) {
            return null;
        }

        app(Stamp::class)->remember($request);

        $url = route($route);

        // HTMX follows a 302 and swaps a whole page into a fragment, so it is
        // told to navigate instead — the existing idiom in this project (see
        // the shipping module's order screens).
        if ($request->hasHeader('HX-Request')) {
            return response('', 200, ['HX-Redirect' => $url]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('This account needs its second factor before it can be used.'),
                'url' => $url,
            ], 403);
        }

        return redirect()->to($url);
    }
}
