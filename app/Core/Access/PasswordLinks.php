<?php

namespace App\Core\Access;

use App\Core\Mail\Letters;
use App\Models\User;
use Illuminate\Support\Facades\Password;

/**
 * "Set your password" as a LINK in a letter (PLAN §41): a manager who lost
 * theirs, a manager just added, a customer whose account was made in the
 * admin. Never a password in a letter — a mailbox keeps letters for years.
 *
 * Laravel's own reset tokens (hashed in `password_reset_tokens`, one per
 * address, used once): the `users` broker for a lost password (an hour),
 * `invites` for an invitation (a week). A manager's link lands in the admin,
 * where the second factor is asked as always after signing in; a
 * customer's lands on the storefront and signs them in.
 */
class PasswordLinks
{
    public static function staffReset(User $user): bool
    {
        $token = Password::broker('users')->createToken($user);

        return Letters::send('account.staff_reset', $user->email, [
            'name' => $user->name,
            'link' => route('admin.password.reset', ['token' => $token, 'email' => $user->email]),
        ], config('app.locale'));
    }

    public static function staffInvite(User $user): bool
    {
        $token = Password::broker('invites')->createToken($user);

        return Letters::send('account.staff_invite', $user->email, [
            'name' => $user->name,
            'email' => $user->email,
            'link' => route('admin.password.reset', ['token' => $token, 'email' => $user->email, 'invite' => 1]),
        ], config('app.locale'));
    }

    public static function customerInvite(User $user, ?string $locale = null): bool
    {
        // No email, no letter: such a customer gets in with a code to their
        // phone (PLAN §44, 3b), and no token is made for nobody to use.
        if (! $user->email) {
            return false;
        }

        $token = Password::broker('invites')->createToken($user);

        return Letters::send('account.customer_invite', $user->email, [
            'name' => $user->name,
            'email' => $user->email,
            'link' => route('account.password.set', ['token' => $token, 'email' => $user->email]),
        ], $locale);
    }
}
