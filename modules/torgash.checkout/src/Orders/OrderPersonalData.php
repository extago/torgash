<?php

namespace Torgash\Checkout\Orders;

use App\Core\Privacy\PersonalData;
use App\Core\Privacy\Subject;
use Carbon\CarbonInterface;
use Torgash\Checkout\Models\Order;

/**
 * What an order holds about a person, for core's personal-data registry
 * (PLAN §49).
 *
 * An order is NOT erased on request: the law asks a shop to keep it for its
 * accounts (and it usually lives in 1C as well). What goes is what the books
 * never needed — the IP address and the browser it was placed from — which is
 * also what expires on its own after the shop's retention.
 *
 * Mass updates, not saves: this is housekeeping, and an order's change funnel
 * (OrderChanges, `checkout.order.changed`) is for things that happen TO an
 * order. Soft-deleted orders are included — a deleted order is still a row.
 */
class OrderPersonalData
{
    public static function register(PersonalData $registry): void
    {
        $registry->register('orders', [
            'label' => 'Orders',
            'export' => fn (Subject $s) => $s->scope(Order::withTrashed())->orderBy('id')->get()
                ->map(fn (Order $order) => [
                    'number' => $order->order_number,
                    'placed_at' => $order->created_at?->toIso8601String(),
                    'status' => $order->status,
                    'total' => $order->total,
                    'currency' => $order->currency_code,
                    'name' => $order->customer_name,
                    'email' => $order->email,
                    'phone' => $order->phone,
                    'company' => $order->company_name,
                    'address' => collect([$order->address_line, $order->city, $order->postal_code, $order->country])->filter()->implode(', '),
                    'items' => collect($order->items ?? [])->map(fn ($item) => [
                        'name' => is_array($item['name'] ?? null) ? pick_translation($item['name']) : ($item['name'] ?? null),
                        'qty' => $item['qty'] ?? null,
                    ])->all(),
                ])->all(),
            'erase' => fn (Subject $s) => $s->scope(Order::withTrashed())
                ->update(['ip_address' => null, 'client' => null]),
            'keep' => 'Orders stay — the law asks a shop to keep them for its accounts; their IP address and browser are removed.',
        ]);

        $registry->register('order_ip', [
            'label' => 'IP address and browser of an order',
            'default_days' => 180,
            'retention' => fn (CarbonInterface $before) => Order::withTrashed()
                ->where('created_at', '<', $before)
                ->where(fn ($q) => $q->whereNotNull('ip_address')->orWhereNotNull('client'))
                ->update(['ip_address' => null, 'client' => null]),
        ]);
    }
}
