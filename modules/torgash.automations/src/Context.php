<?php

namespace Torgash\Automations;

use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;

/**
 * The variables a template can use, and the list of them for the picker.
 *
 * Money appears twice on purpose. `total` is minor units — an integer, which is
 * what a receiving API wants and what cannot be mangled by a locale — and
 * `total_decimal` is "150.00" for the systems that insist on a decimal string.
 * Neither is a formatted price with a currency symbol. For the payloads a
 * PERSON reads — a chat message — there is a third spelling, `total_formatted`
 * ("1 042.25 $", in the currency the customer was quoted in, exactly as the
 * order prints), plus the status's own name and a link to the order in the
 * admin: a manager's phone buzzing with "status: new, total: 104225" is a
 * notification nobody reads twice.
 */
class Context
{
    /**
     * @param  list<string>  $changed
     * @return array<string, mixed>
     */
    public static function forOrder(Order $order, string $event, array $changed = []): array
    {
        return [
            'event' => $event,
            'changed' => implode(',', $changed),
            'shop' => [
                'name' => (string) config('app.name'),
                'url' => (string) config('app.url'),
            ],
            'order' => [
                'id' => $order->id,
                'number' => (string) $order->order_number,
                'status' => (string) $order->status,
                'payment_state' => (string) $order->payment_state,
                'payment_state_label' => PaymentState::label($order->payment_state),
                'currency' => (string) $order->currency_code,
                'subtotal' => $order->subtotal,
                'subtotal_decimal' => self::decimal($order->subtotal),
                'discount' => (int) $order->discount_amount,
                'shipping_cost' => $order->shipping_cost,
                'total' => $order->total,
                'total_decimal' => self::decimal($order->total),
                'total_formatted' => $order->money((int) $order->total),
                'status_label' => (string) (\Torgash\Checkout\Models\OrderStatus::where('code', $order->status)->first()?->t('name') ?: $order->status),
                'admin_url' => route('admin.orders.show', $order->order_number),
                'items_count' => (int) collect($order->items ?? [])->sum(fn ($item) => (int) ($item['qty'] ?? 0)),
                'paid_total' => $order->paid_total,
                'paid_total_decimal' => self::decimal($order->paid_total),
                'customer_name' => (string) $order->customer_name,
                'email' => (string) $order->email,
                'phone' => (string) $order->phone,
                'address_line' => (string) $order->address_line,
                'city' => (string) $order->city,
                'postal_code' => (string) $order->postal_code,
                'country' => (string) $order->country,
                'notes' => (string) $order->notes,
                'manager' => (string) ($order->manager?->name ?? ''),
                'payment_method' => (string) $order->payment_method_name,
                'shipping_method' => (string) $order->shipping_method_name,
                'coupon' => (string) $order->coupon_code,
                'placed_at' => $order->created_at?->toIso8601String() ?? '',
                'items' => array_map(fn (array $item) => [
                    'product_id' => $item['product_id'] ?? null,
                    'sku' => (string) ($item['sku'] ?? ''),
                    'name' => (string) ($item['name'] ?? ''),
                    'qty' => (int) ($item['qty'] ?? 0),
                    'unit_price' => (int) ($item['unit_price'] ?? 0),
                    'unit_price_decimal' => self::decimal((int) ($item['unit_price'] ?? 0)),
                    'line_total' => (int) ($item['line_total'] ?? 0),
                    'line_total_decimal' => self::decimal((int) ($item['line_total'] ?? 0)),
                    'line_total_formatted' => $order->money((int) ($item['line_total'] ?? 0)),
                ], $order->items ?? []),
            ],
        ];
    }

    /**
     * Every variable, grouped and described, for the picker beside the form —
     * so an admin composing a message never has to guess a name, nor whether
     * `total` is "150.00" or 15000. Labels are English source strings.
     *
     * Item fields are shown once, as `item.*`, which is how they are addressed
     * inside a `{{#order.items}}` loop.
     *
     * @return array<string, array<string, string>> group label => [path => label]
     */
    public static function catalogue(): array
    {
        return [
            'Order' => [
                'order.number' => 'Order number',
                'order.status_label' => 'Status, by its name',
                'order.admin_url' => 'Link to the order in the admin',
                'order.placed_at' => 'When it was placed',
                'order.manager' => 'Manager',
                'order.notes' => 'Customer\'s comment',
            ],
            'Customer' => [
                'order.customer_name' => 'Customer name',
                'order.phone' => 'Phone',
                'order.email' => 'Email',
                'order.country' => 'Country',
                'order.city' => 'Town',
                'order.address_line' => 'Address',
                'order.postal_code' => 'Postal code',
            ],
            'Money' => [
                'order.total_formatted' => 'Total with the currency, "1 042.25 $"',
                'order.total_decimal' => 'Total as a number, "1042.25"',
                'order.total' => 'Total in cents, 104225',
                'order.subtotal_decimal' => 'Goods before delivery',
                'order.shipping_cost' => 'Delivery, in cents',
                'order.discount' => 'Discount, in cents',
                'order.paid_total_decimal' => 'Paid so far',
                'order.payment_state_label' => 'Payment state, by its name',
                'order.currency' => 'Currency code',
                'order.coupon' => 'Coupon',
            ],
            'Delivery and payment' => [
                'order.shipping_method' => 'Delivery method',
                'order.payment_method' => 'Payment method',
                'order.items_count' => 'How many items',
            ],
            'Each item (inside the loop)' => [
                'item.name' => 'Product name',
                'item.sku' => 'SKU',
                'item.qty' => 'Quantity',
                'item.line_total_formatted' => 'Line total with the currency',
                'item.unit_price_decimal' => 'Price of one',
                'item.product_id' => 'Product id',
            ],
            'Technical' => [
                'order.id' => 'Order id',
                'order.status' => 'Status code',
                'order.payment_state' => 'Payment state code',
                'event' => 'What triggered the rule',
                'changed' => 'Fields that changed',
                'shop.name' => 'Shop name',
                'shop.url' => 'Shop address',
            ],
        ];
    }

    /**
     * Flat dotted paths, every one the context answers.
     *
     * @return list<string>
     */
    public static function variables(): array
    {
        return [
            'event', 'changed',
            'shop.name', 'shop.url',
            'order.id', 'order.number', 'order.status', 'order.payment_state', 'order.payment_state_label',
            'order.status_label', 'order.admin_url',
            'order.currency', 'order.subtotal', 'order.subtotal_decimal', 'order.discount',
            'order.shipping_cost', 'order.total', 'order.total_decimal', 'order.total_formatted', 'order.items_count',
            'order.paid_total', 'order.paid_total_decimal',
            'order.customer_name', 'order.email', 'order.phone',
            'order.address_line', 'order.city', 'order.postal_code', 'order.country',
            'order.notes', 'order.manager', 'order.payment_method', 'order.shipping_method',
            'order.coupon', 'order.placed_at',
            'item.sku', 'item.name', 'item.qty', 'item.unit_price', 'item.unit_price_decimal',
            'item.line_total', 'item.line_total_decimal', 'item.line_total_formatted', 'item.product_id',
        ];
    }

    protected static function decimal(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }
}
