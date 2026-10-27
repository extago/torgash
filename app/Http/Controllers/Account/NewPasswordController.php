<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * A new password after getting back in by code — no "current password",
 * which is the one thing a person here does not have. Open for fifteen
 * minutes after the code was accepted, in that session only; after that the
 * ordinary form (with the current password) is the way.
 */
class NewPasswordController extends Controller
{
    public const SESSION = 'password_reset_until';

    public function edit(Request $request)
    {
        if (! $this->open($request)) {
            return redirect()->route('account.dashboard');
        }

        return view('account.password-new');
    }

    public function update(Request $request)
    {
        abort_unless($this->open($request), 403);

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->update(['password' => $validated['password']]);
        $request->session()->forget(self::SESSION);

        return redirect()->route('account.dashboard')->with('status', __('Your new password is saved.'));
    }

    protected function open(Request $request): bool
    {
        return (int) $request->session()->get(self::SESSION, 0) > now()->timestamp;
    }
}
