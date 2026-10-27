<?php

namespace App\Http\Controllers\Admin;

use App\Core\Support\SafeMode;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Safe mode on or off for the person asking (App\Core\Support\SafeMode) —
 * their session only, never the shop's. A POST: a GET switch would be
 * followed by the browser's link prefetcher on its own.
 *
 * Goes back to the page it was asked from when that page is on this site
 * (the storefront's bar), else to the storefront's home.
 */
class SafeModeController extends Controller
{
    public function __invoke(Request $request)
    {
        if ($request->boolean('on')) {
            $request->session()->put(SafeMode::SESSION, true);
        } else {
            $request->session()->forget(SafeMode::SESSION);
        }

        $back = (string) $request->input('back', '');
        $host = $request->getSchemeAndHttpHost();

        return redirect(str_starts_with($back, $host.'/') ? $back : (current_storefront()?->url() ?? url('/')));
    }
}
