<?php

namespace Torgash\Guard\Blocking;

/**
 * What an address can be caught doing.
 *
 * A fixed vocabulary in a class, the same shape as `PaymentState` in checkout
 * and for the same reason: this is not a list a shop configures, it is the set
 * of things the engine can actually recognise. A module with a signal of its
 * own calls `Blocks::report()` with its own word — the counter does not care
 * what it is called — but the four here are the ones with a name on the screen.
 *
 * Deliberately short, and each one is an act with NO legitimate reading:
 *
 * - `login` — a password that was wrong. Announced by Laravel's own `Failed`
 *   event, so both doors (the admin's and the customer's) are covered without
 *   either controller knowing this module exists.
 * - `form` — a public form refused by core's `FormGuard`: the honeypot, the
 *   form-age stamp, a rate limit, a CAPTCHA. One refusal is a person having a
 *   bad day; ten in ten minutes is not a person.
 * - `api` — a bearer token that names nobody. Only that: a call with NO token
 *   is a misconfigured integration and an EXPIRED one is yesterday's valid
 *   credential, while a token that never existed is a guess.
 * - `code` — a code that names nothing: a gift card guessed at
 *   (`core.code.failed`, announced by whoever issues such codes). A customer
 *   mistypes once or twice; the counter's threshold is not about them.
 * - `manual` — somebody decided.
 */
class Signals
{
    public const LOGIN = 'login';

    public const FORM = 'form';

    public const API = 'api';

    public const CODE = 'code';

    public const MANUAL = 'manual';

    /** Everything that is counted towards a block. `manual` is a decision, not a count. */
    public const COUNTED = [self::LOGIN, self::FORM, self::API, self::CODE];

    /** In the reader's language, from a stored key. */
    public static function label(string $signal): string
    {
        return match ($signal) {
            self::LOGIN => __('Wrong passwords'),
            self::FORM => __('Refused forms'),
            self::API => __('Unknown API tokens'),
            self::CODE => __('Wrong codes'),
            self::MANUAL => __('Blocked by hand'),
            default => $signal,
        };
    }
}
