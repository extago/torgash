<?php

namespace Torgash\Guard\TwoFactor;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * "This session has answered the second question."
 *
 * One value in the session, and it is what the whole feature turns on. It
 * carries the USER ID rather than just being present: a session that is reused
 * for somebody else — signing out and in as another manager on a shared machine
 * — must not still count as answered, and comparing the id is how that is
 * impossible rather than merely unlikely.
 *
 * It lives exactly as long as the session does and has no expiry of its own.
 * That is the honest reading of what was proved: the person held the phone when
 * this session began. Re-asking mid-session would be asking the same question
 * about the same session twice, and shops that do it are the reason people turn
 * this off.
 *
 * The miss counter sits here too, because it is the same piece of state seen
 * from the other side. It is not a rate limit — clearing the cookie clears it,
 * and clearing the cookie also throws away the password half, which is the
 * point: an attacker who wants a sixth guess has to log in again.
 */
class Stamp
{
    public const KEY = 'guard.two_factor';

    /**
     * Wrong codes before the password is taken back.
     *
     * Five. A person mistyping six digits five times has a different problem;
     * somebody working through a million of them has this many chances per
     * password entry, which is what makes the door arithmetically dull. Not a
     * setting, because there is no shop for whom the right number is 40.
     */
    public const MAX_MISSES = 5;

    public function confirm(User $user): void
    {
        session()->put(self::KEY, ['user' => $user->getKey(), 'at' => now()->timestamp]);
        session()->forget(self::KEY.'.misses');
    }

    public function holds(User $user): bool
    {
        return (int) (session(self::KEY.'.user') ?? 0) === (int) $user->getKey();
    }

    /** Count a wrong answer, and say whether that was one too many. */
    public function miss(): bool
    {
        $misses = (int) session(self::KEY.'.misses', 0) + 1;
        session()->put(self::KEY.'.misses', $misses);

        return $misses >= self::MAX_MISSES;
    }

    public function forget(): void
    {
        session()->forget(self::KEY);
    }

    /**
     * Where they were going when they were stopped, so answering the question
     * puts them back on the page they asked for rather than on a dashboard.
     *
     * Only ever a GET: replaying a POST after a redirect is how a form gets
     * submitted twice, and the second time it happens the shop has two orders.
     */
    public function remember(Request $request): void
    {
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            session()->put('url.intended', $request->fullUrl());
        }
    }
}
