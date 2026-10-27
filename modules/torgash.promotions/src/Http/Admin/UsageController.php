<?php

namespace Torgash\Promotions\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Promotions\Models\Promotion;

/**
 * What the sales actually gave away.
 *
 * The rows have been written since the module existed — CartPricing's snapshot
 * puts one on every order a promotion touched — and until now the only way to
 * read them was SQL. "How much did Black Friday cost us" is the question a shop
 * asks the morning after, and a promotion whose answer is "nothing" is a
 * promotion that never matched anybody: the same reasoning as the automations
 * run log, where a rule that quietly stopped firing looks exactly like a rule
 * nothing triggered.
 *
 * Read from the ORDER SNAPSHOT, never recomputed: what an order was given is a
 * fact about the past, and re-deriving it against today's promotion would
 * report a number the customer never saw.
 */
class UsageController extends Controller
{
    public function index(Request $request)
    {
        $rows = $this->query($request)
            ->select([
                'm_promotions_order_promotions.id',
                'm_promotions_order_promotions.promotion_id',
                'm_promotions_order_promotions.name',
                'm_promotions_order_promotions.amount',
                'm_checkout_orders.order_number',
                'm_checkout_orders.id as order_id',
                'm_checkout_orders.status',
                'm_checkout_orders.currency_code',
                'm_checkout_orders.created_at',
            ])
            ->orderByDesc('m_promotions_order_promotions.id')
            ->paginate(50)
            ->withQueryString();

        return view('torgash.promotions::admin.usage.index', [
            'rows' => $rows,
            'promotions' => Promotion::orderBy('id')->get(),
            // The whole point of the screen, and deliberately the total of what
            // is being LOOKED AT rather than of everything: a filter that does
            // not move the headline number is a filter nobody trusts.
            'given' => (int) -$this->query($request)->sum('m_promotions_order_promotions.amount'),
            'orders' => (int) $this->query($request)->distinct()->count('m_checkout_orders.id'),
        ]);
    }

    protected function query(Request $request)
    {
        return DB::table('m_promotions_order_promotions')
            ->join('m_checkout_orders', 'm_checkout_orders.id', '=', 'm_promotions_order_promotions.order_id')
            ->when($request->filled('promotion'), fn ($q) => $q->where('m_promotions_order_promotions.promotion_id', (int) $request->get('promotion')))
            ->when($request->filled('from'), fn ($q) => $q->where('m_checkout_orders.created_at', '>=', $request->get('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('m_checkout_orders.created_at', '<=', $request->get('to').' 23:59:59'));
    }
}
