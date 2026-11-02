<?php

namespace Torgash\Payments;

use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentSurcharge;

/**
 * The lines a bank is told an order consists of (PLAN §66, stage 5) — one
 * answer for every gateway, so that monobank's basket, WayForPay's product
 * list and Stripe's line items cannot disagree about the same order.
 *
 * A bank that fiscalises (monobank with a PRRO, Checkbox) refuses a list that
 * misses the invoice by a kopeck, so the lines are built to add up to the
 * CHARGE exactly, in the charge currency:
 *
 * - delivery is a line of its own (a delivery paid to the carrier is 0 on the
 *   order and so is no line at all — the bank is not asked for it);
 * - the payment surcharge is a line of its own named as the binding says, or
 *   spread over the goods (`receipt_surcharge`);
 * - everything else between the goods and the charge — discounts, an added
 *   tax, other modules' lines — is spread over the goods by the largest
 *   remainder, and a line whose total no longer divides by its quantity is
 *   split in two (q−r at p, r at p+1), which is what a cashier does;
 * - an order paid only in part now (a deposit, a gift card) gets NO lines: a
 *   receipt for a part of the goods is a different document.
 *
 * Null means "send no lines": switched off on the binding, a part payment,
 * or arithmetic that would not reconcile — and a gateway then sends one line,
 * the order itself, as it always did.
 */
final class Receipt
{
    public const ITEMS = 'receipt_items';

    public const SURCHARGE = 'receipt_surcharge';

    public const SURCHARGE_NAME = 'receipt_surcharge_name';

    /**
     * The binding's fields a gateway that sends lines adds to its own.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields(): array
    {
        return [
            self::ITEMS => [
                'label' => 'Send the order\'s lines to the bank',
                'type' => 'checkbox',
                'default' => true,
                'help' => 'Goods, delivery and the surcharge as lines adding up to the payment — what a fiscal receipt needs. Off: one line, the order. Never sent for a part payment (a deposit, a gift card).',
            ],
            self::SURCHARGE => [
                'label' => 'Payment surcharge in the bank\'s receipt',
                'type' => 'select',
                'default' => 'line',
                'options' => [
                    'line' => 'A line of its own',
                    'spread' => 'Spread over the goods',
                ],
            ],
            self::SURCHARGE_NAME => [
                'label' => 'Name of the surcharge line',
                'type' => 'text',
                'help' => 'Empty — the surcharge\'s own title, as the customer saw it.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config  the binding's
     * @return list<array{name: string, code: string, qty: int, price: int, total: int, kind: string}>|null
     */
    public static function lines(Order $order, Charge $charge, array $config = []): ?array
    {
        if (! ($config[self::ITEMS] ?? true) || $order->chargeNow() !== (int) $order->total || $charge->amount <= 0) {
            return null;
        }

        $fixed = [];

        if ((int) $order->shipping_cost > 0) {
            $fixed[] = self::line($order->shipping_method_name ?: __('Delivery'), 'delivery', 1, $order->chargeMinor((int) $order->shipping_cost), 'delivery');
        }

        $surcharge = (int) ($order->payment_surcharge ?? 0);
        if ($surcharge > 0 && ($config[self::SURCHARGE] ?? 'line') !== 'spread') {
            $name = trim((string) ($config[self::SURCHARGE_NAME] ?? '')) ?: PaymentSurcharge::label($order->payment_surcharge_terms);
            $fixed[] = self::line($name, 'surcharge', 1, $order->chargeMinor($surcharge), 'surcharge');
        }

        $goods = array_values(array_filter((array) ($order->items ?? []), fn ($item) => (int) ($item['qty'] ?? 0) > 0));
        $target = $charge->amount - array_sum(array_column($fixed, 'total'));
        $weights = array_map(fn ($item) => max(0, (int) ($item['line_total'] ?? 0)), $goods);
        $weight = array_sum($weights);

        if ($target < 0 || ($target > 0 && $weight === 0)) {
            return null;
        }

        $lines = [];
        foreach (self::share($target, $weights) as $i => $total) {
            $item = $goods[$i];
            $qty = (int) $item['qty'];
            $name = (string) ($item['name'] ?? '');
            $code = (string) ($item['sku'] ?? '');
            $price = intdiv($total, $qty);
            $odd = $total - $price * $qty;

            if ($odd > 0) {
                $lines[] = self::line($name, $code, $qty - $odd, $price, 'goods');
                $lines[] = self::line($name, $code, $odd, $price + 1, 'goods');
            } else {
                $lines[] = self::line($name, $code, $qty, $price, 'goods');
            }
        }

        $lines = [...array_values(array_filter($lines, fn ($line) => $line['qty'] > 0)), ...$fixed];

        return $lines !== [] && array_sum(array_column($lines, 'total')) === $charge->amount ? $lines : null;
    }

    /**
     * `$amount` split in proportion to `$weights`, by the largest remainder:
     * the parts always add up to it exactly.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    public static function share(int $amount, array $weights): array
    {
        $sum = array_sum($weights);
        if ($weights === [] || $sum === 0) {
            return array_map(fn () => 0, $weights);
        }

        $parts = [];
        $remainders = [];
        foreach ($weights as $i => $weight) {
            $exact = $amount * $weight;
            $parts[$i] = intdiv($exact, $sum);
            $remainders[$i] = $exact % $sum;
        }

        arsort($remainders);
        $left = $amount - array_sum($parts);
        foreach (array_keys($remainders) as $i) {
            if ($left-- <= 0) {
                break;
            }
            $parts[$i]++;
        }

        return array_values($parts);
    }

    /** @return array{name: string, code: string, qty: int, price: int, total: int, kind: string} */
    protected static function line(string $name, string $code, int $qty, int $price, string $kind): array
    {
        return ['name' => $name, 'code' => $code, 'qty' => $qty, 'price' => $price, 'total' => $price * $qty, 'kind' => $kind];
    }
}
