<?php

namespace App\Core\Access;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A session lasts as long as the password it was opened with.
 *
 * The session remembers a stamp of the password; a request whose account has
 * another one is the request of somebody who was in before the change, and it
 * goes on as a guest. It reaches every door at once, because what it compares
 * is the row: the account's form, a code sent to recover it, a manager's
 * screen, `torgash:admin`.
 *
 * Not the framework's `AuthenticateSession`. That one writes the stamp of
 * whoever `$request->user()` is when the response leaves, and a manager
 * looking at the shop as a customer ({@see Impersonation}) is by then the
 * customer: the manager's next request would have been thrown out. So the
 * stamp is per account, written only at a sign-in and at a change of the
 * password, and this runs in `web`, before `ImpersonateCustomer`, where the
 * request still belongs to the person who owns the session.
 *
 * A remembered browser is `remember_token`'s business: `User` replaces it
 * with the password.
 */
class PasswordSessions
{
    public const SESSION = 'password_stamp';

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user instanceof User) {
            $known = $request->session()->get(self::key($user));

            if ($known === null) {
                // A session opened before this check existed.
                self::keep($user);
            } elseif (! hash_equals((string) $known, self::stamp($user))) {
                Auth::logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return $next($request);
    }

    /** This session is the one that goes on with this password. */
    public static function keep(User $user): void
    {
        session()->put(self::key($user), self::stamp($user));
    }

    protected static function key(User $user): string
    {
        return self::SESSION.'.'.$user->getKey();
    }

    /** Keyed, so that what is kept in a session cannot be taken for the hash itself. */
    protected static function stamp(User $user): string
    {
        return hash_hmac('sha256', (string) $user->getAuthPassword(), (string) config('app.key'));
    }
}
