<?php

namespace Torgash\Checkout\Http;

use App\Core\Verification\Channels;
use App\Core\Verification\Verifier;
use App\Http\Controllers\Account\CodeController;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Torgash\Checkout\Orders\PlaceOrder;

/**
 * "This address already has an account" inside the checkout form (PLAN
 * §44.2/1, the owner's mockup): the email box asks here when it is left; if
 * the address is an account's, a panel under it offers a code to that email,
 * takes the six digits and signs the guest in on the spot — the page then
 * reloads prefilled, and the basket, which lives in the session, is still
 * theirs. Every answer is a fragment for the panel; nothing here places an
 * order, and PlaceOrder refuses the address on its own anyway.
 *
 * Asking "is this an account" is counted per visitor: the shop tells the
 * truth (§44.2/2), but not to a script asking a thousand times.
 */
class CheckoutAccountController extends Controller
{
    public const PURPOSE = 'checkout_sign_in';

    public function __construct(protected Verifier $verifier, protected Channels $channels) {}

    public function check(Request $request, PlaceOrder $placer)
    {
        $email = mb_strtolower(trim((string) $request->input('email')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $placer->emailNeedsSignIn($email)) {
            return response('');
        }

        $key = 'checkout-account:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 30)) {
            return response('');
        }
        RateLimiter::hit($key, 600);

        return $this->panel('exists', $email);
    }

    public function send(Request $request, PlaceOrder $placer)
    {
        $email = mb_strtolower(trim((string) $request->input('email')));
        $user = $placer->emailNeedsSignIn($email) ? User::whereRaw('lower(email) = ?', [$email])->first() : null;

        if (! $user) {
            return response('');
        }

        // Staff sign in through the admin and its second factor, not by a
        // code from a shop page.
        if ($user->roles()->exists()) {
            return $this->panel('staff', $email);
        }

        $channel = array_key_first($this->channels->for('email', $email));
        $started = $channel ? $this->verifier->start($request, self::PURPOSE, 'email', $email, $channel, $user) : ['code' => null, 'error' => __('This way of sending a code is not available for this address.')];

        if ($started['error'] || ($problem = CodeController::sendingProblem($started['code']))) {
            return $this->panel('exists', $email, $started['error'] ?? $problem);
        }

        return $this->panel('code', $email, null, $started['code']);
    }

    public function verify(Request $request)
    {
        $row = $this->verifier->current($request, self::PURPOSE);
        $email = mb_strtolower(trim((string) $request->input('email')));

        if (! $row || $row->address !== $email) {
            return $this->panel('exists', $email);
        }

        if ($error = $this->verifier->check($row, (string) $request->input('account_code'))) {
            return $this->panel('code', $email, $error, $row);
        }

        $user = $row->user;
        abort_unless($user && ! $user->roles()->exists(), 404);

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        auth()->login($user);
        $request->session()->regenerate();
        $this->verifier->forget($request, self::PURPOSE);

        // The whole page again, now as them: their name, phone and address
        // fill in, and the order will land in their history.
        return response('')->header('HX-Refresh', 'true');
    }

    public function resend(Request $request)
    {
        $row = $this->verifier->current($request, self::PURPOSE);
        $email = mb_strtolower(trim((string) $request->input('email')));

        if (! $row || $row->address !== $email) {
            return $this->panel('exists', $email);
        }

        $error = $this->verifier->resend($request, $row) ?? CodeController::sendingProblem($row);

        return $this->panel('code', $email, $error, $row);
    }

    protected function panel(string $state, string $email, ?string $error = null, $row = null)
    {
        return view('torgash.checkout::partials.account-panel', [
            'state' => $state,
            'email' => $email,
            'error' => $error,
            'wait' => $row ? $this->verifier->secondsUntilResend($row) : 0,
        ]);
    }
}
