<?php

namespace App\Http\Controllers\Admin;

use App\Core\Admin\Preferences;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * How this person looks at the admin — written by the page the moment they
 * change it (a fetch from the sidebar's own button), read when the next
 * page is drawn, so nothing jumps.
 */
class PreferencesController extends Controller
{
    /** The sidebar as a strip of icons, or whole (PLAN §62, phase 3). */
    public function sidebar(Request $request)
    {
        $mode = $request->validate(['mode' => 'required|in:full,strip'])['mode'];

        Preferences::put($request->user(), 'sidebar', $mode === 'strip' ? 'strip' : null);

        return response()->noContent();
    }
}
