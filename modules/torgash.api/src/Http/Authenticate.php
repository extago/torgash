<?php

namespace Torgash\Api\Http;

use Closure;
use Illuminate\Http\Request;
use Torgash\Api\Models\ApiToken;

/**
 * `Authorization: Bearer tg_…` — and from there, an ordinary logged-in user.
 *
 * The token names a USER, and that user is logged into the request, so every
 * permission check downstream is the same `can()` the admin panel runs. A
 * machine and a person are the same subject with the same rights; only the way
 * they proved who they are differs.
 *
 * Not the `web` group: no session, no CSRF, no locale prefix — the same
 * reasoning as the payments callback route. The locale comes from
 * `Accept-Language` instead (see ApiServiceProvider).
 */
class Authenticate
{
    public function handle(Request $request, Closure $next)
    {
        $plaintext = $request->bearerToken();

        if (! $plaintext) {
            return $this->refuse('No token. Send Authorization: Bearer <token>.');
        }

        // Looked up by hash: the plaintext is never stored, so a stolen dump
        // cannot be replayed. One indexed lookup, no scan, no timing question —
        // the hash is what the unique index is on.
        $token = ApiToken::with('user')->where('token_hash', ApiToken::hash($plaintext))->first();

        if (! $token || ! $token->user) {
            // Said out loud with a reason of its own: a token that never
            // existed is a GUESS, which is not the same event as a call with no
            // token (a misconfigured integration) or an expired one
            // (yesterday's valid credential). Whoever counts such things — the
            // guard's blocking — needs the three kept apart.
            hook_action('api.auth.failed', ['request' => $request, 'reason' => 'unknown_token']);

            return $this->refuse('Unknown token.');
        }

        if ($token->isExpired()) {
            return $this->refuse('This token has expired.');
        }

        auth()->setUser($token->user);
        $request->attributes->set('api_token', $token);

        // Written after the check and without touching updated_at: this is
        // evidence for "is that old integration still running", not a change to
        // the token. At most once a minute: a busy integration otherwise wrote
        // a row per request — and only when the second had changed, which made
        // a request's query count depend on the clock.
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }

    protected function refuse(string $message)
    {
        return response()->json(['message' => __($message)], 401);
    }
}
