<?php

namespace App\Http\Controllers\Admin;

use App\Core\Access\InstallPassword;
use App\Core\Access\LoginThrottle;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('admin.auth.login');
    }

    public function login(Request $request, LoginThrottle $throttle)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $throttle->check($request, $credentials['email']);
        InstallPassword::refuse($request, $throttle, $credentials['email'], $credentials['password']);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $throttle->failed($request, $credentials['email']);

            throw ValidationException::withMessages(['email' => __('Invalid credentials.')]);
        }

        $throttle->succeeded($request, $credentials['email']);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
