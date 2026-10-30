<?php

namespace App\Http\Controllers\Admin;

use App\Core\Admin\AdminMenu;
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

    /**
     * A screen starred or unstarred from the star beside its heading. Only
     * a screen the sidebar shows this person can be starred — a path typed
     * into the request is not a screen.
     */
    public function favourite(Request $request, AdminMenu $menu)
    {
        $data = $request->validate(['path' => 'required|string|max:200', 'on' => 'required|boolean']);
        $path = AdminMenu::pathOf($data['path']);

        abort_unless($menu->knows($path), 422);

        $starred = array_values(array_diff((array) Preferences::get($request->user(), AdminMenu::FAVOURITES, []), [$path]));
        if ($data['on']) {
            $starred[] = $path;
        }

        Preferences::put($request->user(), AdminMenu::FAVOURITES, $starred ?: null);

        return response()->noContent();
    }

    /** The sidebar showing the starred screens alone, or all of them. */
    public function view(Request $request)
    {
        $view = $request->validate(['view' => 'required|in:all,favourites'])['view'];

        Preferences::put($request->user(), AdminMenu::VIEW, $view === 'favourites' ? 'favourites' : null);

        return response()->noContent();
    }
}
