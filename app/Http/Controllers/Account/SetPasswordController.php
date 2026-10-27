<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Where a customer lands from "an account was made for you"
 * (App\Core\Access\PasswordLinks::customerInvite): choose a password and be
 * signed in. The link proved the mailbox, so the email counts as confirmed.
 * Customers only — a manager's invitation opens the admin's own page, where
 * the second factor waits.
 */
class SetPasswordController extends Controller
{
    public function edit(Request $request, string $token)
    {
        return view('account.password-set', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $customer = null;

        $status = Password::broker('invites')->reset($data, function (User $user, string $password) use (&$customer) {
            abort_if($user->roles()->exists(), 403);

            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            event(new PasswordReset($user));
            $customer = $user;
        });

        if ($status !== Password::PASSWORD_RESET || ! $customer) {
            return back()->withErrors(['password' => __('The link is no longer valid. Restore access by a code instead.')]);
        }

        Auth::login($customer);
        $request->session()->regenerate();

        return redirect()->route('account.dashboard')->with('status', __('Your password is set — welcome!'));
    }
}
