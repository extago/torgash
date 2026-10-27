<?php

namespace App\Http\Controllers\Account;

use App\Core\Access\InstallPassword;
use App\Core\Access\LoginThrottle;
use App\Core\Support\Phones;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function create()
    {
        return view('account.login');
    }

    public function store(Request $request, LoginThrottle $throttle)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required'],
        ]);

        $throttle->check($request, $data['email']);
        InstallPassword::refuse($request, $throttle, $data['email'], $data['password']);

        // The box takes an email or — once proven on the account — a phone,
        // read the way its own country writes it (App\Core\Support\Phones).
        $phone = str_contains($data['email'], '@') ? null : Phones::parse($data['email']);
        $credentials = $phone
            ? ['phone' => $phone['e164'], 'password' => $data['password'], fn ($q) => $q->whereNotNull('phone_verified_at')]
            : ['email' => $data['email'], 'password' => $data['password']];

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $throttle->failed($request, $data['email']);

            throw ValidationException::withMessages(['email' => __('These credentials do not match our records.')]);
        }

        $throttle->succeeded($request, $data['email']);
        $request->session()->regenerate();

        return redirect()->intended(route('account.dashboard'));
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('catalog.index');
    }
}
