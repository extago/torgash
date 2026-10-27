<?php

namespace Torgash\Guard\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Torgash\Guard\TwoFactor\Enrolment;
use Torgash\Guard\TwoFactor\Qr;
use Torgash\Guard\TwoFactor\Requirement;
use Torgash\Guard\TwoFactor\Stamp;
use Torgash\Guard\TwoFactor\Totp;

/**
 * The Security page, both of them.
 *
 * A manager sets this up in the admin and a customer in their account, and the
 * five acts are identical — begin, prove, show the paper codes, replace them,
 * turn it off. What differs is the layout it is drawn in and where the buttons
 * post to, so that is all the two subclasses say. Writing it twice would be two
 * places for "does turning this off need the password" to be answered
 * differently, and the wrong answer would be in the one nobody reviewed.
 *
 * Three rules the screen enforces, none of them about TOTP:
 *
 * 1. **Enrolment writes on a POST, never on the GET.** A page that mints a
 *    secret by being looked at mints one for every crawler and every
 *    accidental refresh.
 * 2. **The current password is asked for whatever WEAKENS the account** —
 *    turning it off, and replacing the recovery codes, which throws away the
 *    ones on the fridge. Not for turning it on: the person is already logged
 *    in and is making themselves harder to impersonate, and a password box
 *    there is friction bought with nothing.
 * 3. **The recovery codes are not printed on a page that merely loads.** They
 *    are shown once, at the moment they are made, and after that only to
 *    somebody who types the password again — a list of eight working
 *    credentials should not be readable by whoever walks past the screen.
 */
abstract class SecurityScreen extends Controller
{
    /**
     * Where the codes go between the POST that makes them and the page.
     *
     * Deliberately NOT nested under the session key `Stamp` writes: that key is
     * replaced outright when a session is stamped as answered, and a flash
     * living inside it would survive or vanish depending on which of the two
     * happened last.
     */
    public const FLASH = 'guard.recovery_codes';

    public function __construct(protected Enrolment $enrolment, protected Requirement $requirement) {}

    abstract protected function view(): string;

    /** The name of this screen's own GET route — every action redirects back. */
    abstract protected function route(): string;

    public function show(Request $request)
    {
        $user = $request->user();

        abort_unless($this->requirement->offered($user), 404);

        $record = $this->enrolment->record($user);
        $pending = $record && ! $record->isConfirmed();

        // Every name is prefixed, and that is this project's Blade lesson
        // rather than a style: the panel below is an @include, an include
        // inherits every variable in its caller's scope, and a partial that
        // reads $route or $required would happily take whatever some loop two
        // hundred lines up a theme's template left behind.
        return view($this->view(), [
            'tfRecord' => $record?->isConfirmed() ? $record : null,
            // An enrolment in progress: the secret exists and has not been
            // proved yet. Refreshing the page shows the same one, which is what
            // somebody who lost the tab needs.
            'tfSecret' => $pending ? $record->secret : null,
            'tfQr' => $pending ? Qr::svg(Totp::uri($record->secret, \App\Core\Access\AccountContact::label($user), Qr::issuer())) : '',
            'tfReadable' => $pending ? Totp::readable($record->secret) : null,
            'tfRequired' => $this->requirement->required($user),
            'tfCodes' => session(self::FLASH, []),
            'tfRoute' => $this->route(),
        ]);
    }

    public function start(Request $request)
    {
        $user = $request->user();

        abort_unless($this->requirement->offered($user), 404);

        $this->enrolment->start($user);

        return redirect()->route($this->route());
    }

    public function confirm(Request $request)
    {
        $user = $request->user();

        abort_unless($this->requirement->offered($user), 404);

        $request->validate(['code' => ['required', 'string']]);

        $codes = $this->enrolment->confirm($user, (string) $request->input('code'));

        if ($codes === null) {
            throw ValidationException::withMessages([
                'code' => __('That code is not right. Check the clock on your phone and try the current one.'),
            ]);
        }

        // The session that just armed it counts as having answered: making
        // somebody who has this second literally proved they hold the phone
        // type another code is theatre, and the next page would be the
        // challenge screen.
        app(Stamp::class)->confirm($user);

        return redirect()->route($this->route())
            ->with(self::FLASH, $codes)
            ->with('status', __('Two-factor authentication is on.'));
    }

    /** Show them again — behind the password, and without touching them. */
    public function codes(Request $request)
    {
        $record = $this->armed($request);

        $this->confirmPassword($request);

        return redirect()->route($this->route())->with(self::FLASH, $record->recovery_codes ?? []);
    }

    /**
     * A fresh set. Destructive — every code on the piece of paper stops working
     * — so it asks for the password like turning the whole thing off does.
     */
    public function newCodes(Request $request)
    {
        $this->armed($request);

        $this->confirmPassword($request);

        return redirect()->route($this->route())
            ->with(self::FLASH, $this->enrolment->regenerateCodes($request->user()))
            ->with('status', __('New recovery codes. The old ones no longer work.'));
    }

    public function disable(Request $request)
    {
        $user = $request->user();

        abort_unless($this->requirement->offered($user), 404);

        $this->confirmPassword($request);

        // The shop may be insisting on one. Refused here as well as hidden on
        // the screen: a button that is not drawn is not a rule.
        if ($this->requirement->required($user)) {
            throw ValidationException::withMessages([
                'current_password' => __('This shop requires two-factor authentication for your account, so it cannot be switched off. Ask an administrator.'),
            ]);
        }

        $this->enrolment->disable($user);

        return redirect()->route($this->route())
            ->with('status', __('Two-factor authentication is off.'));
    }

    protected function armed(Request $request)
    {
        $record = $this->enrolment->active($request->user());

        abort_unless($record && $this->requirement->offered($request->user()), 404);

        return $record;
    }

    /**
     * `current_password` is Laravel's own rule against the logged-in user's
     * hash — a wrong one is a validation error on the field the person typed
     * into, not a 403 they cannot act on.
     */
    protected function confirmPassword(Request $request): void
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
    }
}
