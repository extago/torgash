<?php

namespace App\Core\Access;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The password a fresh install is seeded with (`admin`) opens a developer's
 * machine and nothing else. A live shop gets its owner from
 * `php artisan torgash:admin`, and even if an old seeded account survived
 * the move, typing `admin` at either login form refuses — the one password
 * every scanner tries first must never be the one that opens the orders.
 *
 * Asked BEFORE the attempt, whoever the account: nobody is signed in and out
 * again (no login event, no remembered cookie), and the answer is the same
 * for an account that exists and one that does not. It counts as a miss, so
 * a scanner trying it walks into the same pause as any other guess.
 */
class InstallPassword
{
    public const PASSWORD = 'admin';

    /** Where the seeded account exists and its password works: a developer's machine and the test suite. */
    public static function allowedHere(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    public static function refuse(Request $request, LoginThrottle $throttle, string $login, string $typed): void
    {
        if ($typed !== self::PASSWORD || self::allowedHere()) {
            return;
        }

        $throttle->failed($request, $login);

        throw ValidationException::withMessages(['email' => __('This is the installation password, and it does not open a live shop. The owner sets a real one on the server: php artisan torgash:admin <email>')]);
    }
}
