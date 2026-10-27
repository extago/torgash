<?php

namespace Torgash\Inventory\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Inventory\Profit;

/**
 * Profit over a period (PLAN §42): the SALES of those days — orders in a
 * status that takes goods off the shelf, the dashboard's own definition — each
 * with its goods, delivery, cost, expenses, income and what is left, and the
 * sum of all of them. A merged order is inside the one it went into.
 */
class ProfitController extends Controller
{
    public function index(Request $request, Profit $profit)
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        $sales = DB::table('m_checkout_orders')
            ->whereNull('deleted_at')
            ->whereNull('merged_into_id')
            ->whereIn('status', OrderStatus::where('inventory_action', 'decrease')->pluck('code'))
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        $page = (clone $sales)->orderByDesc('id')->paginate(50, ['id', 'order_number', 'created_at', 'currency_code'])->withQueryString();

        return view('torgash.inventory::admin.profit', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'orders' => $page,
            'lines' => $profit->forOrders(collect($page->items())->pluck('id')->all()),
            'totals' => $profit->totals($sales),
            'byLabel' => DB::table('m_inventory_order_costs')
                ->whereIn('order_id', (clone $sales)->select('id'))
                ->selectRaw('kind, label, sum(amount) as amount, count(*) as n')
                ->groupBy('kind', 'label')->orderByDesc('amount')->get(),
        ]);
    }
}
