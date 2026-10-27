<?php

namespace Torgash\Guard\Http;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Torgash\Guard\TwoFactor\Attempts;
use Torgash\Guard\TwoFactor\Enrolment;
use Torgash\Guard\TwoFactor\Qr;
use Torgash\Guard\TwoFactor\Requirement;
use Torgash\Guard\TwoFactor\Stamp;

/**
 * The code box: one screen for both halves of the shop.
 *
 * A manager arriving from `/admin/login` and a customer arriving from the
 * storefront are asked the same question in the same words, so there is one
 * page and it stands on its own — no admin chrome and no storefront theme.
 * That is a decision rather than laziness: somebody who is halfway in should
 * not be handed a navigation menu, because every item on it would bounce
 * straight back to this page, and a shop's header full of dead links is how a
 * person concludes the site is broken and stops.
 *
 * There is no "trust this device for 30 days" and no email fallback. Both are
 * ways of not asking the question, and the second is worse than nothing: it
 * makes the second factor exactly as strong as the mailbox the password reset
 * already goes to.
 */
class ChallengeController extends Controller
{
    public function __construct(
        protected Enrolment $enrolment,
        protected Requirement $requirement,
        protected Stamp $stamp,
        protected Attempts $attempts,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();

        if ($redirect = $this->settled($request)) {
            return $redirect;
        }

        return view('torgash.guard::two-factor.challenge', [
            'email' => \App\Core\Access\AccountContact::label($user),
            'codesLeft' => $this->enrolment->active($user)?->codesLeft() ?? 0,
            // The way out, in whichever half of the shop they belong to. A page
            // that can only be left by clearing cookies is a trap, and the
            // person most likely to need this is the one whose phone is gone.
            'logout' => route($this->requirement->isStaff($user) ? 'admin.logout' : 'account.logout'),
            'shop' => Qr::issuer(),
        ]);
    }

    public function verify(Request $request)
    {
        $user = $request->user();

        if ($redirect = $this->settled($request)) {
            return $redirect;
        }

        $request->validate(['code' => ['required', 'string', 'max:64']]);

        // The account's own count comes first, before the code is even read:
        // past it, a right guess must not get in either (see Attempts).
        if ($this->attempts->locked($user)) {
            return $this->signOut($request, $this->lockedMessage($user));
        }

        $how = $this->enrolment->verify($user, (string) $request->input('code'));

        if ($how === null) {
            $this->attempts->miss($user);

            // A wrong second factor is a failed sign-in, and is announced as
            // one — which is what this module's blocking counts per address.
            event(new Failed('web', $user, ['two_factor' => true]));

            return $this->refuse($request);
        }

        $this->attempts->clear($user);
        $this->stamp->confirm($user);

        // A recovery code was spent, so they are sent to their own security
        // page rather than to wherever they were going. It is the only place
        // that can answer the two things they now need — how many are left, and
        // "this phone is gone, arm a new one" — and a flashed sentence on a
        // catalogue page is not the same as being stood in front of the button.
        if ($how === Enrolment::BY_RECOVERY) {
            return redirect()->route($this->securityRoute($user))->with('status', __(
                'You signed in with a recovery code. :n are left — if you have lost your phone, turn two-factor authentication off here and set it up again on the new one.',
                ['n' => $this->enrolment->active($user)?->codesLeft() ?? 0],
            ));
        }

        return redirect()->intended(route($this->homeRoute($user)));
    }

    /**
     * Nothing to ask: they have no second factor, or this session has already
     * answered. Sending them home rather than drawing an empty box — the
     * middleware is what puts people here, and anybody who arrives by typing
     * the address deserves a sensible answer.
     */
    protected function settled(Request $request)
    {
        $user = $request->user();

        if (! $this->enrolment->active($user) || $this->stamp->holds($user)) {
            return redirect()->route($this->homeRoute($user));
        }

        return null;
    }

    /**
     * A wrong code, and past the fifth one the password is taken back.
     *
     * Logging them out is the strongest thing this screen can do and the
     * cheapest: it costs an honest person one re-login and costs a guesser the
     * password again, which is what the failed-login signal in this module's
     * own blocking counts. Not a rate limit on the code box — a limit that
     * leaves the session open is a limit that waits. The account's own count
     * ({@see Attempts}) is what makes signing in again no way round it.
     */
    protected function refuse(Request $request)
    {
        $user = $request->user();

        if ($this->attempts->locked($user)) {
            return $this->signOut($request, $this->lockedMessage($user));
        }

        if (! $this->stamp->miss()) {
            throw ValidationException::withMessages([
                'code' => __('That code is not right. Try the one showing now, or use a recovery code.'),
            ]);
        }

        return $this->signOut($request, __('Too many wrong codes. Please sign in again.'));
    }

    /** The password taken back, with the reason on the sign-in page it lands on. */
    protected function signOut(Request $request, string $message)
    {
        $staff = $this->requirement->isStaff($request->user());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($staff ? 'admin.login' : 'account.login')
            ->withErrors(['email' => $message]);
    }

    protected function lockedMessage($user): string
    {
        return trans_choice(
            'Too many wrong codes for this account. Try again in :count minute.|Too many wrong codes for this account. Try again in :count minutes.',
            max(1, (int) ceil($this->attempts->secondsLeft($user) / 60)),
        );
    }

    protected function homeRoute($user): string
    {
        return $this->requirement->isStaff($user) ? 'admin.dashboard' : 'account.dashboard';
    }

    protected function securityRoute($user): string
    {
        return $this->requirement->isStaff($user) ? 'admin.guard.security' : 'account.security';
    }
}
