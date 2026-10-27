<?php

namespace Torgash\Promotions\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Orders\OrderTotals;
use Torgash\Promotions\PromotionsServiceProvider;

/**
 * "Recheck the promotions" on a placed order (PLAN §40.6/4): run today's
 * promotions over what the order holds and show "was −380, becomes −420"
 * before anything moves. Applying is an ordinary recalculation through
 * OrderTotals — which is what re-snapshots the promotions, the taxes and the
 * total together — and a line in the order's history.
 */
class OrderRecheckController extends Controller
{
    public function preview(Request $request, Order $order)
    {
        abort_unless($request->user()?->can('orders.manage'), 403);

        $now = (int) DB::table('m_promotions_order_promotions')->where('order_id', $order->id)->sum('amount');
        $lines = app()->getProvider(PromotionsServiceProvider::class)->pricedLines($order);

        return view('torgash.promotions::admin.order-recheck', [
            'order' => $order,
            'recheck' => [
                'was' => $now,
                'becomes' => array_sum(array_column($lines, 'amount')),
                'lines' => $lines,
            ],
        ]);
    }

    public function apply(Request $request, Order $order, OrderTotals $totals)
    {
        abort_unless($request->user()?->can('orders.manage'), 403);

        $was = (int) DB::table('m_promotions_order_promotions')->where('order_id', $order->id)->sum('amount');

        DB::transaction(function () use ($order, $totals, $was) {
            // An ordinary recalculation keeps what was granted on the lines it
            // was granted for; this one is the manager asking today's
            // promotions over everything the order holds.
            app()->getProvider(PromotionsServiceProvider::class)->reprice($order);
            $totals->recalculate($order);
            $becomes = (int) DB::table('m_promotions_order_promotions')->where('order_id', $order->id)->sum('amount');

            OrderLog::record($order, 'Promotions rechecked: :from → :to', [
                'from' => $order->adminMoney($was),
                'to' => $order->adminMoney($becomes),
            ]);
        });

        return view('torgash.checkout::admin.orders._editor', [
            'order' => $order->fresh(),
            'editing' => true,
            'oob' => true,
        ]);
    }
}
