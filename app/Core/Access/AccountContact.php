<?php

namespace App\Core\Access;

use App\Core\Verification\Channels;
use App\Models\User;

/**
 * Which contact a customer's account must have (PLAN §44, step 3b): the
 * shop's setting `core.account_contact` — `email`, `phone`, `either` (at
 * least one) or `both` — read in one place for registration, the admin's
 * customer form and the profile's "remove".
 *
 * A phone can only be required while the shop can send a code to a phone
 * (a channel that offers it): an account made by phone is signed into and
 * restored by that code, so without one the setting falls back to `email`
 * rather than making accounts nobody can get back into.
 */
class AccountContact
{
    public const EMAIL = 'email';

    public const PHONE = 'phone';

    public const EITHER = 'either';

    public const BOTH = 'both';

    public function __construct(protected Channels $channels) {}

    /** The rule in force, after what the shop can actually send. */
    public function mode(): string
    {
        $mode = (string) setting('core', 'account_contact', self::EMAIL);

        if (! in_array($mode, [self::EMAIL, self::PHONE, self::EITHER, self::BOTH], true)) {
            return self::EMAIL;
        }

        return $mode !== self::EMAIL && ! $this->phoneWorks() ? self::EMAIL : $mode;
    }

    public function phoneWorks(): bool
    {
        return $this->channels->offers('phone');
    }

    /**
     * Is this field on the form at all? An email always is — optional where
     * the phone is the rule, since letters still need one; a phone wherever
     * the rule mentions it (in `email` it is added later, in the profile).
     */
    public function asks(string $kind): bool
    {
        return $kind === self::EMAIL || $this->mode() !== self::EMAIL;
    }

    /** Must it be filled in, whatever the other one says? */
    public function requires(string $kind): bool
    {
        $mode = $this->mode();

        return $mode === self::BOTH || $mode === $kind;
    }

    /** `either`: one of the two, but which is the customer's choice. */
    public function eitherWillDo(): bool
    {
        return $this->mode() === self::EITHER;
    }

    /**
     * Why an account may not be left with these contacts, or null when it
     * may. `$email` / `$phone` are what the account will hold (null = none);
     * a phone counts only once proven, since that is what signs in with it.
     */
    public function refusal(?string $email, ?string $phone, bool $phoneProven): ?string
    {
        $hasEmail = filled($email);
        $hasPhone = filled($phone) && $phoneProven;

        if (! $hasEmail && ! $hasPhone) {
            return __('An account needs an email or a confirmed phone — there would be no way to sign in.');
        }

        return match ($this->mode()) {
            self::EMAIL => $hasEmail ? null : __('An email is required.'),
            self::PHONE => $hasPhone ? null : __('A confirmed phone is required.'),
            self::BOTH => $hasEmail && $hasPhone ? null : __('Both an email and a confirmed phone are required.'),
            default => null,
        };
    }

    /** May this account let go of this contact now? Null = yes, else why not. */
    public function removalRefusal(User $user, string $kind): ?string
    {
        return $kind === self::EMAIL
            ? $this->refusal(null, $user->phone, (bool) $user->phone_verified_at)
            : $this->refusal($user->email, null, false);
    }

    /** What to call an account in a list or a line: its email, else its phone. */
    public static function label(User $user): string
    {
        return (string) ($user->email ?: $user->phone ?: '#'.$user->id);
    }
}
