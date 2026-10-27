<?php

namespace App\Http\Controllers\Admin;

use App\Core\Access\Handoff;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Where a ticket from another of the shop's addresses lands (App\Core\Access\Handoff). */
class HandoffController extends Controller
{
    public function __invoke(Request $request, Handoff $handoff, string $token)
    {
        $ticket = $handoff->take($request, $token);

        if ($ticket === null) {
            return redirect()->route('admin.login')
                ->withErrors(['email' => __('The link has expired or was already used. Sign in here, or open it from the admin again.')]);
        }

        $user = $ticket['user'];

        // Somebody else signed in on this address: their session is theirs,
        // not something to inherit.
        if (Auth::check() && (int) Auth::id() !== (int) $user->getKey()) {
            Auth::logout();
            $request->session()->invalidate();
        }

        Auth::login($user);
        $request->session()->regenerate();

        foreach ($ticket['session'] as $key => $value) {
            $request->session()->put($key, $value);
        }

        hook_action('core.auth.handed_over', ['user' => $user, 'storefront' => $ticket['storefront'], 'path' => $ticket['path']]);

        return redirect($ticket['path']);
    }
}
