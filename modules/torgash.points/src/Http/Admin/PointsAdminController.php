<?php

namespace Torgash\Points\Http\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Models\Order;
use Torgash\Points\Ledger;
use Torgash\Points\Models\Entry;
use Torgash\Points\OrderFlow;

/**
 * Bonus points in the admin: the latest movements and a correction by hand.
 * The rules are {@see RulesController}'s; a customer's own balance is on
 * their card; an order's on its payment card.
 */
class PointsAdminController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $entries = Entry::query()
            ->when($q !== '', fn ($query) => $query->whereIn('user_id', User::where('email', 'ilike', '%'.$q.'%')->orWhere('phone', 'like', '%'.(preg_replace('/\D+/', '', $q) ?: '~').'%')->orWhere('name', 'ilike', '%'.$q.'%')->select('id')))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('torgash.points::admin.index', [
            'entries' => $entries,
            'users' => User::whereIn('id', $entries->pluck('user_id'))->get(['id', 'name', 'email'])->keyBy('id'),
            'orders' => Order::withTrashed()->whereIn('id', $entries->pluck('order_id')->filter())->pluck('order_number', 'id'),
            'q' => $q,
            'outstanding' => (int) DB::table('m_points_accounts')->where('balance', '>', 0)->sum('balance'),
        ]);
    }

    /** A correction by hand: whose, how many, and a reason the customer will read. */
    public function adjust(Request $request, Ledger $ledger)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'points' => 'required|integer|not_in:0',
            'reason' => 'required|string|max:500',
        ]);

        $user = User::whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->first();

        if (! $user) {
            return back()->withErrors(['email' => __('No account uses this email.')])->withInput();
        }

        try {
            $ledger->adjust($user->id, (int) $data['points'], $data['reason']);
        } catch (\InvalidArgumentException) {
            return back()->withErrors(['points' => __('A balance cannot go below zero.')])->withInput();
        }

        return back()->with('status', __('Balance corrected.'));
    }

    /** The manager's own number for what an order earns. */
    public function orderEarn(Request $request, Order $order, OrderFlow $flow)
    {
        abort_unless($request->user()?->can('orders.manage'), 403);
        $data = $request->validate(['points' => 'required|integer|min:0|max:10000000']);

        $flow->setEarn($order, (int) $data['points']);

        return back()->with('status', __('Saved.'));
    }
}
