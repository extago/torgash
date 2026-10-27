<?php

namespace Torgash\Checkout\Admin;

use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Models\Order;

/**
 * What the admin's customer screens are told about a customer's ORDERS.
 *
 * Core owns customers and knows nothing of orders, so it asks two hooks and
 * this module answers both: `core.admin.customers.facts` for a page of the
 * list (one query for the whole page, never one per row) and
 * `core.admin.customer.panels` for the card.
 *
 * "Paid" is `paid_total`, the payment axis, summed in the books — never
 * `total` filtered by a list of statuses somebody would have to keep in step
 * with the shop's own workflow. An order that was never paid added nothing,
 * a refunded one took it back, and that is already what the column says.
 */
class CustomerOrders
{
    public function register(): void
    {
        hook_listen('core.admin.customers.facts', function (array $facts, array $context) {
            if (! (auth()->user()?->can('orders.manage'))) {
                return $facts;
            }

            foreach ($this->facts((array) ($context['ids'] ?? [])) as $userId => $rows) {
                $facts[$userId] = [...($facts[$userId] ?? []), ...$rows];
            }

            return $facts;
        });

        hook_listen('core.admin.customer.panels', function (array $panels, array $context) {
            $customer = $context['customer'] ?? null;

            if (! $customer || ! auth()->user()?->can('orders.manage')) {
                return $panels;
            }

            return [...$panels, ['key' => 'orders', 'position' => 10, 'html' => $this->panel($customer)]];
        });

        // Said to whoever is deleting, whatever they may see: the orders are
        // the shop's books and outlive the account (the key goes null, the
        // name and address were copied onto each order when it was placed).
        hook_listen('core.admin.customer.delete_notes', function (array $notes, array $context) {
            $count = ($customer = $context['customer'] ?? null) ? Order::where('user_id', $customer->id)->count() : 0;

            return $count === 0 ? $notes : [...$notes, ['key' => 'orders', 'deleted' => false,
                'label' => trans_choice(':count order stays, under the name and address it was placed with.|:count orders stay, under the name and address they were placed with.', $count, ['count' => $count])]];
        });
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, list<array{key: string, label: string, value: string, hint?: string, url?: string}>>
     */
    public function facts(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('m_checkout_orders')
            ->whereIn('user_id', $ids)
            ->whereNull('deleted_at')
            ->groupBy('user_id')
            ->selectRaw('user_id, count(*) as orders, coalesce(sum(paid_total), 0) as paid, max(created_at) as last_at')
            ->get();

        $facts = [];

        foreach ($rows as $row) {
            $facts[(int) $row->user_id] = [[
                'key' => 'orders',
                'label' => trans_choice(':count order|:count orders', (int) $row->orders, ['count' => (int) $row->orders]),
                'value' => __('paid :amount', ['amount' => admin_price((int) $row->paid, shop_currency())]),
                'hint' => __('last :when', ['when' => \Illuminate\Support\Carbon::parse($row->last_at)->diffForHumans()]),
                'url' => route('admin.orders.index', ['customer' => $row->user_id]),
                'sort' => (int) $row->paid,
            ]];
        }

        return $facts;
    }

    protected function panel($customer): string
    {
        $query = Order::where('user_id', $customer->id);

        return view('torgash.checkout::admin.customers.orders', [
            'customer' => $customer,
            'orders' => (clone $query)->latest()->limit(10)->get(),
            'count' => (clone $query)->count(),
            'paid' => (int) (clone $query)->sum('paid_total'),
            'statuses' => app(\Torgash\Checkout\Support\Statuses::class),
        ])->render();
    }
}
