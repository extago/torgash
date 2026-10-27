<?php

namespace Torgash\Checkout\Mail;

use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;

/**
 * The letters about an order (PLAN §41), in core's registry: the
 * confirmation, and one letter PER STATUS — so each status has words of its
 * own, and a status nobody needs to hear about ("Processing") can be switched
 * off, as a shop could in older engines. The order's lines, sums and address are a
 * BLOCK (`{order_details}`): what the order is may not be retyped by hand.
 */
class CheckoutLetters
{
    public const PLACED = 'checkout.order_placed';

    public const NEW_ORDER = 'checkout.new_order';

    public static function statusEvent(string $code): string
    {
        return 'checkout.order_status.'.$code;
    }

    /** @return array<string, array<string, mixed>> */
    public static function events(): array
    {
        $events = [
            self::PLACED => [
                'label' => 'Order confirmation',
                'group' => 'Orders',
                'audience' => 'customer',
                'subject' => 'Order {order_number} confirmed',
                'heading' => 'Thank you for your order!',
                'body' => "Hi {customer_name}, we have your order {order_number} and will be in touch about it.\n\n{order_details}",
                'button' => ['label' => 'Track this order', 'url' => '{order_url}'],
                'help' => self::help(),
                'tokens' => fn (array $c) => self::tokens($c['order'] ?? null),
                'blocks' => fn (array $c) => self::blocks($c['order'] ?? null),
                'sample' => fn () => ['order' => Order::latest('id')->first()],
            ],
        ];

        // To the SHOP — a director who wants every order in their inbox. No
        // default address: it goes nowhere until one is written on its page.
        $events[self::NEW_ORDER] = [
            'label' => 'A new order',
            'group' => 'Orders',
            'audience' => 'shop',
            'subject' => 'New order {order_number}: {total}',
            'heading' => 'New order {order_number}',
            'body' => "{customer_name}, {phone}\n\n{order_details}",
            'button' => ['label' => 'Open in the admin panel', 'url' => '{admin_url}'],
            'help' => self::help() + ['phone' => 'The customer\'s phone', 'admin_url' => 'The order in the admin'],
            'tokens' => fn (array $c) => self::tokens($c['order'] ?? null) + [
                'phone' => (string) (($c['order'] ?? null)?->phone ?? ''),
                'admin_url' => ($c['order'] ?? null) ? url('/admin/orders/'.$c['order']->order_number) : '',
            ],
            'blocks' => fn (array $c) => self::blocks($c['order'] ?? null),
            'reply_to' => fn (array $c) => ($c['order'] ?? null)?->email,
            'recipients' => fn () => [],
            'sample' => fn () => ['order' => Order::latest('id')->first()],
        ];

        foreach (OrderStatus::orderBy('position')->get() as $status) {
            $events[self::statusEvent($status->code)] = [
                // A status's name is the shop's content, not a declared word.
                'label' => __('Order status: :status', ['status' => $status->t('name')]),
                'group' => 'Orders',
                'audience' => 'customer',
                'subject' => 'Order {order_number}: {status}',
                'heading' => '{status}',
                'body' => "Hi {customer_name},\n\nThe status of your order {order_number} has changed to: {status}.\n\nTotal: {total}",
                'button' => ['label' => 'Track this order', 'url' => '{order_url}'],
                'help' => self::help() + ['status' => 'The new status'],
                'tokens' => fn (array $c) => self::tokens($c['order'] ?? null) + [
                    'status' => ($c['status'] ?? $status)?->t('name') ?? '',
                ],
                'blocks' => fn (array $c) => self::blocks($c['order'] ?? null),
                'sample' => fn () => ['order' => Order::latest('id')->first(), 'status' => $status],
            ];
        }

        return $events;
    }

    /** @return array<string, string> */
    protected static function help(): array
    {
        return [
            'customer_name' => 'The customer\'s name',
            'order_number' => 'Order number',
            'total' => 'The order\'s total',
            'order_url' => 'The link to the order',
            'order_details' => 'Lines, sums, delivery and address (on a line of its own)',
        ];
    }

    /** @return array<string, string> */
    protected static function tokens(?Order $order): array
    {
        if (! $order) {
            return [];
        }

        return [
            'customer_name' => (string) $order->customer_name,
            'order_number' => (string) $order->order_number,
            'total' => $order->money($order->total),
            'order_url' => $order->customerUrl(),
        ];
    }

    /** @return array<string, string> */
    protected static function blocks(?Order $order): array
    {
        return $order ? ['order_details' => view('torgash.checkout::mail.order-details', ['order' => $order])->render()] : [];
    }
}
