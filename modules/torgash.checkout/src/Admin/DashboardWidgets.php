<?php

namespace Torgash\Checkout\Admin;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;

/**
 * The orders' widgets on the admin dashboard (core's
 * `core.admin.dashboard.widgets`): sales, the latest orders, where orders
 * stand, and what sells.
 *
 * "A sale" is an order in a status that takes goods off the shelf
 * (`inventory_action = decrease` — open, processing, shipped, complete in a
 * stock install): the shop's own statement of which orders are real. An
 * order still awaiting payment, cancelled or declined is not money, and
 * counting it would make a day of failed card payments look like a good day.
 * Amounts are the books (`total`), printed with `admin_price()`.
 */
class DashboardWidgets
{
    public function register(): void
    {
        hook_listen('core.admin.dashboard.widgets', function (array $widgets) {
            $widgets[] = [
                'key' => 'checkout.sales',
                'title' => 'Sales',
                'icon' => 'chart-column',
                'size' => 'wide',
                'position' => 10,
                'can' => 'orders.manage',
                'link' => ['url' => route('admin.orders.index'), 'label' => 'All orders'],
                'render' => fn () => view('torgash.checkout::admin.dashboard.sales', $this->sales())->render(),
            ];
            $widgets[] = [
                'key' => 'checkout.latest',
                'title' => 'Latest orders',
                'icon' => 'package',
                'size' => 'half',
                'position' => 20,
                'can' => 'orders.manage',
                'link' => ['url' => route('admin.orders.index'), 'label' => 'All orders'],
                'render' => fn () => view('torgash.checkout::admin.dashboard.latest', [
                    'orders' => Order::latest('id')->limit(8)->get(),
                    'statuses' => $this->statuses(),
                ])->render(),
            ];
            $widgets[] = [
                'key' => 'checkout.statuses',
                'title' => 'Orders by status',
                'icon' => 'list-todo',
                'size' => 'quarter',
                'position' => 25,
                'can' => 'orders.manage',
                'render' => fn () => view('torgash.checkout::admin.dashboard.statuses', [
                    'counts' => Order::query()->toBase()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status'),
                    'statuses' => $this->statuses(),
                ])->render(),
            ];
            $widgets[] = [
                'key' => 'checkout.top_products',
                'title' => 'Best sellers, 30 days',
                'icon' => 'trophy',
                'size' => 'quarter',
                'position' => 30,
                'can' => 'orders.manage',
                'render' => fn () => view('torgash.checkout::admin.dashboard.top', ['rows' => $this->topProducts()])->render(),
            ];

            return $widgets;
        });
    }

    /** @return list<string> */
    protected function counted(): array
    {
        return OrderStatus::where('inventory_action', 'decrease')->pluck('code')->all();
    }

    protected function statuses()
    {
        return OrderStatus::orderBy('position')->get()->keyBy('code');
    }

    /** Headline numbers for three periods, the month before for comparison, and one bar per day. */
    protected function sales(): array
    {
        $counted = $this->counted();
        $today = Carbon::today();

        $period = function (Carbon $from, ?Carbon $to = null) use ($counted) {
            $row = Order::query()->toBase()
                ->whereIn('status', $counted)
                ->where('created_at', '>=', $from)
                ->when($to, fn ($q) => $q->where('created_at', '<', $to))
                ->selectRaw('count(*) as orders, coalesce(sum(total), 0) as revenue')
                ->first();

            return ['orders' => (int) $row->orders, 'revenue' => (int) $row->revenue];
        };

        $month = $period($today->copy()->subDays(29));
        $previous = $period($today->copy()->subDays(59), $today->copy()->subDays(29));

        $byDay = Order::query()->toBase()
            ->whereIn('status', $counted)
            ->where('created_at', '>=', $today->copy()->subDays(29))
            ->selectRaw('created_at::date as day, count(*) as orders, coalesce(sum(total), 0) as revenue')
            ->groupBy('day')
            ->get()->keyBy(fn ($r) => (string) $r->day);

        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i);
            $row = $byDay[$date->toDateString()] ?? null;
            $days[] = ['date' => $date, 'orders' => (int) ($row->orders ?? 0), 'revenue' => (int) ($row->revenue ?? 0)];
        }

        return [
            'today' => $period($today),
            'week' => $period($today->copy()->subDays(6)),
            'month' => $month,
            'previous' => $previous,
            'average' => $month['orders'] ? intdiv($month['revenue'], $month['orders']) : 0,
            'days' => $days,
            'awaiting' => ($awaiting = OrderStatus::awaitingPayment()) ? Order::where('status', $awaiting->code)->count() : 0,
        ];
    }

    /**
     * What sold most in thirty days, by units — counted from the orders' own
     * line snapshots, parts of a configured line included since they are goods
     * too. The NAME is the catalogue's, in the reader's language: a snapshot is
     * in whatever language each buyer shopped in, so a Russian manager read an
     * English best-seller list. The snapshot only names a product since deleted.
     *
     * @return list<object>
     */
    protected function topProducts(): array
    {
        $counted = $this->counted();

        if ($counted === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($counted), '?'));

        $rows = DB::select("
            select (item->>'product_id')::bigint as product_id,
                   max(item->>'name') as name,
                   sum(coalesce((item->>'qty')::int, 0)) as qty,
                   sum(coalesce((item->>'line_total')::bigint, 0)) as revenue
            from m_checkout_orders o, jsonb_array_elements(o.items) item
            where o.deleted_at is null
              and o.status in ($placeholders)
              and o.created_at >= ?
              and item->>'product_id' is not null
            group by 1
            order by qty desc, revenue desc
            limit 6
        ", [...$counted, now()->subDays(30)]);

        $products = Product::query()->whereIn('id', array_column($rows, 'product_id'))->get(['id', 'name'])->keyBy('id');

        foreach ($rows as $row) {
            $row->name = $products[$row->product_id]?->t('name') ?? $row->name;
        }

        return $rows;
    }
}
