<?php

namespace App\Http\Controllers\Admin;

use App\Core\Access\PasswordLinks;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * "Forgot your password?" at the admin's door, and the page a link from an
 * invitation or a reset lands on (PasswordLinks). The same answer whether
 * the address is a manager's or not — this door guards the whole shop, and
 * unlike the storefront's it has no reason to say who works here. Setting
 * the password does NOT sign in: the ordinary login follows, with the second
 * factor, so a mailbox alone never opens the admin.
 */
class PasswordController extends Controller
{
    public function forgot()
    {
        return view('admin.auth.forgot');
    }

    public function send(Request $request)
    {
        $email = mb_strtolower(trim((string) $request->validate(['email' => ['required', 'email', 'max:190']])['email']));

        $user = User::staff()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user) {
            PasswordLinks::staffReset($user);
        }

        return back()->with('status', __('If this address belongs to somebody who works here, a letter with a link is on its way. The link works for an hour.'));
    }

    public function edit(Request $request, string $token)
    {
        return view('admin.auth.reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
            'invite' => $request->boolean('invite'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $broker = $request->boolean('invite') ? 'invites' : 'users';

        $status = Password::broker($broker)->reset($data, function (User $user, string $password) {
            // A customer's address with a reset token is not a way into the admin.
            abort_unless($user->roles()->exists(), 403);

            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

            if (! $user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => __('The link is no longer valid. Ask for a new one.')]);
        }

        return redirect()->route('admin.login')->with('status', __('The password is set. Sign in with it.'));
    }
}
