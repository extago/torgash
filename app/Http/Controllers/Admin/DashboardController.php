<?php

namespace App\Http\Controllers\Admin;

use App\Core\Admin\Dashboard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Dashboard $dashboard)
    {
        return view('admin.dashboard', [
            'board' => $dashboard->board(auth()->user()),
            'dashboard' => $dashboard,
        ]);
    }

    /**
     * The person's own arrangement: the columns, which widget stands in which
     * zone and in what order, and what is hidden. `hide` alone (the eye in a
     * widget's header) hides that one and leaves the rest as it was.
     */
    public function layout(Request $request, Dashboard $dashboard)
    {
        $user = $request->user();

        if ($request->filled('hide')) {
            $dashboard->hide($user, (string) $request->input('hide'));

            return redirect()->route('admin.dashboard');
        }

        if ($request->boolean('reset')) {
            $dashboard->reset($user);

            return redirect()->route('admin.dashboard')->with('status', __('The dashboard is back to how it was laid out.'));
        }

        $zones = [];
        foreach ((array) $request->input('zones', []) as $zone => $keys) {
            $zones[(string) $zone] = array_map('strval', (array) $keys);
        }

        $dashboard->save(
            $user,
            (string) $request->input('layout', Dashboard::DEFAULT_LAYOUT),
            $zones,
            array_map('strval', (array) $request->input('hidden', [])),
        );

        return redirect()->route('admin.dashboard')->with('status', __('Dashboard saved.'));
    }

    /** The top bar's bell, redrawn every minute (App\Core\Admin\AdminNotifications). */
    public function notifications()
    {
        return view('admin.partials.notifications');
    }
}
