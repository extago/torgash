<?php

namespace App\Core\Access;

use App\Core\Support\ClientAddress;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * How many wrong passwords a sign-in form takes before it pauses.
 *
 * Core's, and always on: both doors are core's (the admin's and the
 * customer's), and a shop must not need an optional module switched from
 * "watch" to "block" before its password box stops answering a guesser as
 * fast as it can ask. The guard's blocking still counts the same failures
 * (Laravel's `Failed` event) and can shut a whole address out; this is the
 * floor under it.
 *
 * Two counters. Per LOGIN from one client: five wrong passwords a minute,
 * the number Laravel's own starter kits use — a person who mistyped stays
 * well inside it. Per CLIENT, whatever logins it tries: thirty in ten
 * minutes, which is what stops one address walking a list of accounts. A
 * client is an IPv4 address or an IPv6 /64 (`ClientAddress`), so changing the
 * last group of an IPv6 address is not a fresh allowance. There is no counter
 * per account across all addresses on purpose: anybody could then lock any
 * customer — or the shop's owner — out of their own account by guessing at it.
 */
class LoginThrottle
{
    public const PER_LOGIN = 5;

    public const PER_LOGIN_SECONDS = 60;

    public const PER_CLIENT = 30;

    public const PER_CLIENT_SECONDS = 600;

    /** Refuses the attempt before the password is even looked at, when either counter is full. */
    public function check(Request $request, string $login): void
    {
        $seconds = max(
            RateLimiter::tooManyAttempts($this->loginKey($request, $login), self::PER_LOGIN) ? RateLimiter::availableIn($this->loginKey($request, $login)) : 0,
            RateLimiter::tooManyAttempts($this->clientKey($request), self::PER_CLIENT) ? RateLimiter::availableIn($this->clientKey($request)) : 0,
        );

        if ($seconds === 0) {
            return;
        }

        event(new Lockout($request));

        throw ValidationException::withMessages(['email' => self::wait($seconds)]);
    }

    public function failed(Request $request, string $login): void
    {
        RateLimiter::hit($this->loginKey($request, $login), self::PER_LOGIN_SECONDS);
        RateLimiter::hit($this->clientKey($request), self::PER_CLIENT_SECONDS);
    }

    /** The right password forgives that login's misses — not the client's. */
    public function succeeded(Request $request, string $login): void
    {
        RateLimiter::clear($this->loginKey($request, $login));
    }

    /** "Try again in 40 seconds" / "in 7 minutes" — whichever reads naturally. */
    public static function wait(int $seconds): string
    {
        return $seconds > 90
            ? trans_choice('Too many attempts to sign in. Try again in :count minute.|Too many attempts to sign in. Try again in :count minutes.', (int) ceil($seconds / 60))
            : trans_choice('Too many attempts to sign in. Try again in :count second.|Too many attempts to sign in. Try again in :count seconds.', $seconds);
    }

    protected function loginKey(Request $request, string $login): string
    {
        return 'login:'.sha1(Str::lower(trim($login)).'|'.ClientAddress::subject($request->ip()));
    }

    protected function clientKey(Request $request): string
    {
        return 'login-client:'.sha1(ClientAddress::subject($request->ip()));
    }
}
