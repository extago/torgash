<?php

namespace Torgash\Checkout\Api;

use Torgash\Api\ApiResources;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Orders\OrderStatusChanger;
use Torgash\Checkout\Orders\PaymentState;

/**
 * Orders on the API — readable, and moved by NAMED transitions.
 *
 * The transitions are the point. `PATCH {status: "complete"}` would write a
 * column and look like it had worked: no stock taken, no log line, no email,
 * and a shop whose warehouse silently stopped matching its orders.
 * `POST /orders/TG-1/actions/status` runs `OrderStatusChanger` — the same code
 * the select in the admin panel runs — so there is exactly one way an order's
 * status moves, whoever moved it. Sylius separated its checkout API from its
 * state machine for this reason; it is the one place their design is worth
 * copying outright.
 *
 * The same goes for money: `paid_total` is written by PaymentApplier from a
 * gateway's word, and an exchange marking an order paid goes through the same
 * door rather than around it.
 */
class CheckoutResources
{
    public function register(): void
    {
        ApiResources::register('admin.orders', [
            'label' => 'Orders',
            'description' => 'Orders as placed. Readable in full, but moved only by named transitions: `status` is the shop’s own editable workflow and `payment` is a second axis, never a status.',
            'permission' => 'orders.manage',
            'query' => fn () => Order::query(),
            'sortable' => ['id', 'order_number', 'created_at', 'updated_at', 'total'],
            'lookup' => ['external_id', 'order_number'],
            'filters' => [
                'status' => [
                    'description' => 'One of the shop’s own order statuses.',
                    'apply' => fn ($q, $v) => $q->where('status', $v),
                ],
                'payment_state' => [
                    'description' => 'The fixed payment vocabulary — unpaid, pending, paid, refunded and the like. A separate axis from `status`, which is why a shop here can say “paid and shipped”.',
                    'apply' => fn ($q, $v) => $q->where('payment_state', $v),
                ],
                'email' => ['apply' => fn ($q, $v) => $q->where('email', $v)],
                'updated_after' => [
                    'description' => 'Everything touched since a moment (ISO 8601).',
                    'apply' => fn ($q, $v) => $q->where('updated_at', '>=', $v),
                ],
            ],
            'serialize' => fn (Order $o) => $this->order($o),
            'writable' => [
                'external_id' => ['type' => 'string|null', 'description' => 'What your own system calls this order.'],
            ],
            // Extended by whoever adds to the answer through
            // `api.order.serialize`, so the page describes what is really sent.
            'schema' => hook_filter('api.order.schema', $this->schema()),
            // Only the exchange's own field: everything else about an order is
            // either a decision (status, payment) with a transition of its own,
            // or a record of what the customer agreed to and not ours to edit
            // from a script.
            'update' => function (Order $order, array $data) {
                if (array_key_exists('external_id', $data)) {
                    $order->update(['external_id' => $data['external_id'] ?: null]);
                }
            },
            'actions' => [
                'status' => [
                    'description' => 'Move the order to another status — the same code the select in the admin panel runs, so the stock moves, the change is logged and the customer’s email goes out. A PATCH setting the column would have looked like it worked and done none of it.',
                    'body' => [
                        'status' => ['type' => 'string', 'description' => 'The status’ own code, as it appears in `status` on the order.', 'example' => 'complete'],
                    ],
                    'apply' => fn (Order $order, array $data) => app(OrderStatusChanger::class)->change(
                        $order,
                        OrderStatus::findOrFail((string) ($data['status'] ?? '')),
                    ),
                ],
            ],
        ]);
    }

    /**
     * What the serialiser answers with, said in words — read by
     * `Torgash\Api\OpenApi`, ignored by the runtime. It lives beside the
     * serialiser so the two are changed in one edit.
     *
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        return [
            'id' => 'integer',
            'url' => 'string',
            'external_id' => 'string|null',
            'number' => ['type' => 'string', 'description' => 'What the customer and the shop call this order.', 'example' => 'TG-000123'],
            'status' => ['type' => 'string', 'description' => 'The shop’s own editable workflow. Moved through `actions/status`, never by a PATCH.'],
            'payment' => ['type' => 'object', 'description' => 'A second axis, never a status.', 'of' => [
                'state' => ['type' => 'string', 'description' => 'The fixed vocabulary the engine and every gateway agree on.'],
                'paid_total' => ['type' => 'string|null', 'description' => 'Decimal string in major units of `currency`, two places. Not derivable from the state: an underpayment and a partial refund are both “some, not all”.'],
                'prepaid_total' => ['type' => 'string', 'description' => 'The part of `paid_total` paid before any gateway was asked — a gift card. A refund gives it back first.'],
                'deposit' => ['type' => 'string|null', 'description' => 'Set when the order was sold with only part paid online and the rest on delivery: that part, frozen at placement.'],
                'surcharge' => ['type' => 'string', 'description' => 'What the payment method added to the bill (a percent of the goods after discounts, with or without the delivery, plus a fixed amount). Inside `total`, and a line of `adjustments` with code `payment_surcharge`.'],
                'paid_at' => ['type' => 'string|null', 'format' => 'date-time'],
                'method' => ['type' => 'string|null', 'description' => 'The name as the customer was sold it, printed for ever after.'],
                'method_id' => ['type' => 'integer|null', 'description' => 'The link — which gateway, which currencies. Renaming a method does not detach it.'],
                'currency' => ['type' => 'string|null', 'description' => 'What the bank is asked for, which is not always what the order is written in.'],
                'amount' => ['type' => 'string|null', 'description' => 'Decimal string in the currency of the line above: what the bank is asked for, which is the total minus `prepaid_total`.'],
            ]],
            'customer' => ['type' => 'object', 'of' => [
                'name' => 'string', 'email' => 'string', 'phone' => 'string|null',
                'user_id' => ['type' => 'integer|null', 'description' => 'Null for a guest.'],
            ]],
            'delivery' => ['type' => 'object', 'of' => [
                'method' => ['type' => 'string|null', 'description' => 'The name as it read when the order was placed.'],
                'address' => 'string|null', 'city' => 'string|null',
                'country' => 'string|null', 'postal_code' => 'string|null',
                'cost' => 'string|null',
                'paid_to_carrier' => ['type' => 'boolean', 'description' => 'The recipient pays the carrier on receipt: `cost` is 0 and the delivery is not in `total`.'],
                'carrier_cost' => ['type' => 'string|null', 'description' => 'What the carrier was expected to ask when the order was placed; null when nobody could say.'],
                'details' => ['type' => 'object|null', 'description' => 'What the chosen option asked for on top of the address — a branch to collect from, a carrier’s own tracking.'],
            ]],
            'currency' => ['type' => 'string', 'description' => 'The shop’s books — the units every amount on this order is stored in, and what its reports are in.'],
            'subtotal' => ['type' => 'string', 'description' => 'Decimal string in major units, two places: "199990.00".'],
            'discount' => ['type' => 'string', 'description' => 'Decimal string, two places.'],
            'total' => ['type' => 'string', 'description' => 'Decimal string, two places.'],
            'items' => ['type' => 'array', 'description' => 'The lines as bought: what they were called and cost then, not what the catalogue says now. `unit_price` is a price per unit as a decimal string with the places the shop keeps ("0.0120"); `line_total` an amount with two ("12.00").'],
            'adjustments' => ['type' => 'array', 'description' => 'The structured breakdown of the total — taxes, fees — read from what this order was actually charged rather than recomputed. `included` means the amount is already inside the prices and is disclosed, not added.', 'of' => [
                'code' => 'string', 'label' => 'string', 'amount' => 'string', 'included' => 'boolean',
            ]],
            'notes' => 'string|null',
            'fields' => ['type' => 'array', 'description' => 'The shop’s own checkout questions as answered: `code`, the question as it read (`label`) and the answer (`value`), in the shop’s base language, in the order asked.', 'of' => [
                'code' => 'string', 'label' => 'string', 'value' => 'string',
            ]],
            'source' => ['type' => 'string|null', 'description' => 'How it was placed: checkout, one_click, admin, api, exchange; null on an order from before that was recorded.'],
            'locale' => ['type' => 'string|null', 'description' => 'The language the customer bought in — what a reprint and an email are written in.'],
            'ip_address' => ['type' => 'string|null', 'description' => 'The address the order was placed from. Null for an order nobody placed from a browser — a manager’s exchange, an import.'],
            'created_at' => ['type' => 'string|null', 'format' => 'date-time'],
            'updated_at' => ['type' => 'string|null', 'format' => 'date-time'],
        ];
    }

    /**
     * An order line as the API writes it: `unit_price` (and an option's
     * `amount`) a price per unit, `line_total` an amount — both decimal
     * strings (PLAN §65).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function apiLine(array $item): array
    {
        if (array_key_exists('unit_price', $item)) {
            $item['unit_price'] = api_price((int) $item['unit_price']);
        }

        if (array_key_exists('line_total', $item)) {
            $item['line_total'] = api_money((int) $item['line_total']);
        }

        if (isset($item['options']) && is_array($item['options'])) {
            $item['options'] = array_map(fn ($o) => is_array($o) && isset($o['amount']) ? ['amount' => api_price((int) $o['amount'])] + $o : $o, $item['options']);
        }

        return $item;
    }

    /** @return array<string, mixed> */
    protected function order(Order $order): array
    {
        return hook_filter('api.order.serialize', [
            'id' => $order->id,
            'url' => url("/api/v2/admin/orders/{$order->id}"),
            'external_id' => $order->external_id,
            'number' => $order->order_number,
            'status' => $order->status,
            'payment' => [
                'state' => $order->payment_state,
                'paid_total' => api_money($order->paid_total),
                // The part of it paid before any gateway (a gift card).
                'prepaid_total' => api_money($order->prepaid_total),
                // Part online, the rest on delivery: what the bank is asked for.
                'deposit' => api_money($order->deposit_amount),
                // What the method added to the bill (PLAN §66) — also a line of `adjustments`.
                'surcharge' => api_money((int) $order->payment_surcharge),
                'paid_at' => $order->paid_at?->toIso8601String(),
                'method' => $order->payment_method_name,
                'method_id' => $order->payment_method_id,
                // What the bank is actually asked for, when that is not the
                // currency the order is written in (§52).
                'currency' => $order->paymentCurrency(),
                'amount' => api_money($order->chargeMinor($order->chargeNow())),
            ],
            'customer' => [
                'name' => $order->customer_name,
                'email' => $order->email,
                'phone' => $order->phone,
                'user_id' => $order->user_id,
            ],
            'delivery' => [
                'method' => $order->shipping_method_name,
                'address' => $order->address_line,
                'city' => $order->city,
                'country' => $order->country,
                'postal_code' => $order->postal_code,
                'cost' => api_money($order->shipping_cost),
                // Paid by the recipient to the carrier (PLAN §66): `cost` is 0
                // and this is the estimate, null when nobody could say.
                'paid_to_carrier' => (bool) $order->shipping_to_carrier,
                'carrier_cost' => $order->shipping_to_carrier && $order->shipping_carrier_cost !== null ? api_money((int) $order->shipping_carrier_cost) : null,
                'details' => $order->shipping_details,
            ],
            // Decimal strings in major units of `currency`, the shop's books
            // (PLAN §65): amounts with two places, prices per unit with the
            // places the shop keeps.
            'currency' => $order->currency_code,
            'subtotal' => api_money($order->subtotal),
            'discount' => api_money($order->discount_amount),
            'total' => api_money($order->total),
            'items' => array_map(fn (array $item) => self::apiLine($item), (array) $order->items),
            // The structured breakdown of the total (taxes, fees), read from
            // what this order was actually charged rather than recomputed —
            // §15.1.7, answered by whichever modules added lines to it.
            'adjustments' => array_map(
                fn (array $line) => ['amount' => api_money((int) ($line['amount'] ?? 0))] + $line,
                hook_filter('checkout.order.extra_totals', [], ['order' => $order]),
            ),
            'notes' => $order->notes,
            'fields' => $order->fields ?? [],
            'source' => $order->source,
            'locale' => $order->locale,
            'ip_address' => $order->ip_address,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ], ['order' => $order]);
    }
}
