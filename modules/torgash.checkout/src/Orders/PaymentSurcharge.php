<?php

namespace Torgash\Checkout\Orders;

use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;

/**
 * What a payment method adds to the bill (PLAN §66) — the one place that
 * arithmetic lives, for a basket being checked out and for a placed order
 * alike.
 *
 * A percent of the goods after EVERY discount (the coupon, bonus points, a
 * cart promotion's cut), with the delivery when the method says so, plus a
 * fixed amount. Taxes are not in the base: a surcharge on the VAT is a tax on
 * a tax. A discount for paying a certain way is not a negative surcharge — it
 * is a cart promotion with the "Payment method" condition.
 *
 * What it is measured against, and what it comes to, is frozen onto the order
 * as TERMS (`payment_surcharge_terms`), the way a coupon is: an edit of the
 * order's contents recomputes it from them, and editing the method later does
 * not reach a placed order.
 */
final class PaymentSurcharge
{
    /** Its line among the totals — `checkout.extra_totals` / `checkout.order.extra_totals`. */
    public const CODE = 'payment_surcharge';

    public const BASES = [
        'goods' => 'Goods after discounts',
        'goods_shipping' => 'Goods after discounts and delivery',
    ];

    /** What the list of payment methods says beside this one (`surcharge_display`). */
    public const DISPLAYS = [
        'percent' => 'The rate (+10%)',
        'amount' => 'The amount for this order',
        'none' => 'Nothing',
    ];

    /**
     * How the bill shows it (`surcharge_in_totals`) — frozen into the terms,
     * so an order prints it the way it was sold. Hidden is still IN the
     * total: only the line naming it is not drawn for the customer; the
     * admin, the API and 1C always see it.
     */
    public const IN_TOTALS = [
        'line' => 'A line of its own',
        'hidden' => 'No line, inside the total',
    ];

    /**
     * The method's terms, or null when it adds nothing.
     *
     * @return array{percent: int, fixed: int, base: string, title: array<string, string>|null, shown: bool}|null
     */
    public static function termsOf(?PaymentMethod $method): ?array
    {
        if (! $method) {
            return null;
        }

        $percent = max(0, (int) $method->surcharge_percent);
        $fixed = max(0, (int) $method->surcharge_fixed);

        if ($percent === 0 && $fixed === 0) {
            return null;
        }

        return [
            'percent' => $percent,
            'fixed' => $fixed,
            'base' => $method->surcharge_base === 'goods_shipping' ? 'goods_shipping' : 'goods',
            'title' => array_filter((array) ($method->surcharge_title ?? [])) ?: null,
            'shown' => $method->surcharge_in_totals !== 'hidden',
        ];
    }

    /**
     * What the terms come to, for goods (after the coupon and bonus points)
     * and a delivery, less whatever the other lines of the bill cut from them
     * — a promotion's discount of the goods, or of the delivery.
     *
     * Nothing owed is nothing to add to: a fixed fee on an order a promotion
     * made free would be the only thing the customer pays.
     *
     * @param  list<array{code?: string, amount: int, included: bool, target?: string}>  $lines
     */
    public static function amount(?array $terms, int $goods, int $shipping, array $lines = []): int
    {
        if (! $terms) {
            return 0;
        }

        foreach ($lines as $line) {
            if (($line['code'] ?? '') === self::CODE || ($line['included'] ?? false) || (int) $line['amount'] >= 0) {
                continue;
            }

            if (($line['target'] ?? 'goods') === 'shipping') {
                $shipping += (int) $line['amount'];
            } else {
                $goods += (int) $line['amount'];
            }
        }

        $goods = max(0, $goods);
        $shipping = max(0, $shipping);

        if ($goods + $shipping <= 0) {
            return 0;
        }

        $base = $goods + ($terms['base'] === 'goods_shipping' ? $shipping : 0);

        return round_money($base * (int) $terms['percent'] / 10000) + (int) $terms['fixed'];
    }

    /**
     * The surcharge a placed order owes now, from its own terms — the
     * contents may have changed since it was placed (OrderTotals).
     */
    public static function forOrder(Order $order): int
    {
        $terms = $order->payment_surcharge_terms;

        if (! $terms) {
            return 0;
        }

        $lines = hook_filter('checkout.order.extra_totals', [], ['order' => $order]);

        return self::amount(
            $terms,
            max(0, (int) $order->subtotal - (int) $order->discount_amount),
            (int) $order->shipping_cost,
            is_array($lines) ? $lines : [],
        );
    }

    /** What the line is called — the method's own title, in the reader's language. */
    public static function label(?array $terms): string
    {
        $title = $terms['title'] ?? null;

        return (is_array($title) ? (string) pick_translation($title) : '') ?: __('Payment surcharge');
    }

    /**
     * Its line among the totals. `hidden` — the shop chose not to name it in
     * the bill: still summed (it is not `included`), just not drawn on the
     * customer's pages (confirmation, invoice, the checkout's summary).
     *
     * @return array{code: string, label: string, amount: int, included: bool, hidden: bool}
     */
    public static function line(int $amount, ?array $terms): array
    {
        return [
            'code' => self::CODE,
            'label' => self::label($terms),
            'amount' => $amount,
            'included' => false,
            'hidden' => ! ($terms['shown'] ?? true),
        ];
    }

    /**
     * What the list of methods says beside this one's name: the rate, or the
     * amount for this basket, or nothing (`surcharge_display`).
     *
     * @param  int|null  $amount  what it comes to here, when the caller knows
     */
    public static function hint(PaymentMethod $method, ?int $amount, callable $money): ?string
    {
        $terms = self::termsOf($method);

        if (! $terms || $method->surcharge_display === 'none') {
            return null;
        }

        if ($method->surcharge_display === 'amount' && $amount !== null) {
            return $amount > 0 ? '+'.$money($amount) : null;
        }

        $parts = [];
        if ($terms['percent'] > 0) {
            $parts[] = '+'.rtrim(rtrim(number_format($terms['percent'] / 100, 2, ',', ''), '0'), ',').'%';
        }
        if ($terms['fixed'] > 0) {
            $parts[] = '+'.$money($terms['fixed']);
        }

        return implode(' ', $parts);
    }
}
