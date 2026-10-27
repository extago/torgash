<?php

namespace Torgash\Guard\TwoFactor;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Wrong second-factor answers for ONE ACCOUNT, whichever session gave them.
 *
 * The session's own counter ({@see Stamp::MAX_MISSES}) takes the password
 * back after five wrong codes. On its own that is a limit that waits: somebody
 * who already holds the password signs in again and has five more, as often
 * as they like, and a million six-digit codes are then a matter of patience.
 * This counter belongs to the account, so signing in again does not reset it.
 * Ten an hour leaves a person fumbling with a new phone room to get it right,
 * and leaves a guesser — who must also pass the password every five — years
 * from a likely hit.
 *
 * It does not lock the account itself out of anything else: the password, a
 * reset by code and a recovery code all keep working once the hour is up.
 */
class Attempts
{
    public const PER_ACCOUNT = 10;

    public const DECAY_SECONDS = 3600;

    public function locked(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->key($user), self::PER_ACCOUNT);
    }

    public function secondsLeft(User $user): int
    {
        return RateLimiter::availableIn($this->key($user));
    }

    public function miss(User $user): void
    {
        RateLimiter::hit($this->key($user), self::DECAY_SECONDS);
    }

    public function clear(User $user): void
    {
        RateLimiter::clear($this->key($user));
    }

    protected function key(User $user): string
    {
        return 'guard-two-factor:'.$user->getKey();
    }
}
