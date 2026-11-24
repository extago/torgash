<?php

namespace Torgash\Points;

use App\Models\User;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;

/**
 * How many points money earns (PLAN §40.5, §74): "N points for every X" from
 * the settings, unless the product's own answer or a rule ({@see Rules}) says
 * otherwise — a multiplier of that rate, a percent of the price, a fixed
 * number per piece, or nothing — and times the customer group's multiplier.
 *
 * Counted from the GOODS after every discount, never from delivery. What a
 * gift card paid earns like money (the card itself, when it was bought,
 * earned nothing — it is outside the programme) unless the shop chose to earn
 * on money alone.
 */
class Earning
{
    public function __construct(protected Rules $rules) {}

    /**
     * A page of cards announces itself (`catalog.products.listed`): what the
     * rules ask about them in one query per kind, or "+N points" on a grid is
     * a query per card.
     *
     * @param  iterable<Product|int>  $products
     */
    public function prime(iterable $products): void
    {
        $this->rules->prime($products);
    }

    /** Points one MINOR unit of money earns, before anything else. */
    public function rate(): float
    {
        $points = (float) setting('torgash.points', 'earn_points', 1);
        $per = (float) setting('torgash.points', 'earn_per', 100) * 100;

        return $per > 0 ? $points / $per : 0.0;
    }

    /**
     * Points for an amount of money (minor units) that is `$qty` pieces,
     * by the terms worked out for them — not yet rounded.
     *
     * @param  array{mode: string, value: float|int}  $terms
     */
    public function points(array $terms, int $amount, int $qty): float
    {
        return match ($terms['mode']) {
            'multiplier' => $amount * $this->rate() * (float) $terms['value'],
            'percent' => $amount / 100 * (float) $terms['value'] / 100,
            'fixed' => (float) $terms['value'] * $qty,
            'none' => 0.0,
            default => $amount * $this->rate(),
        };
    }

    /**
     * What buying this product at this price (price units, as
     * `effectivePrice()` answers) earns — "+N points" on a card.
     */
    public function forPrice(Product $product, int $price, ?User $user = null): int
    {
        $unit = price_scale()->toMinor($price);
        $terms = $this->rules->earn((int) $product->id, $unit, $user);

        return (int) floor($this->points($terms, $unit, 1) * $this->rules->groupMultiplier($user));
    }

    /** What an order earns. */
    public function forOrder(Order $order): int
    {
        return $this->plan($order)[0];
    }

    /**
     * What an order earns and BY WHAT: its goods lines, each by its own
     * terms, reduced by the order's discount in proportion, reduced again by
     * what a gift card paid when the shop earns on money alone.
     *
     * `$kept` are the terms the order was planned by. A line that had terms
     * keeps them — a rule edited after the order was placed does not move
     * it; a line added since gets today's.
     *
     * @param  array<string, mixed>|null  $kept
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function plan(Order $order, ?array $kept = null): array
    {
        if (! $order->user_id) {
            return [0, []];
        }

        $lines = array_filter((array) $order->items, fn ($line) => is_array($line) && empty($line['parent_key']) && ! empty($line['product_id']));
        $subtotal = max(1, (int) $order->subtotal);
        $afterDiscount = max(0, $subtotal - (int) $order->discount_amount) / $subtotal;
        $money = 1.0;

        if (setting('torgash.points', 'money_only', false) && (int) $order->total > 0) {
            $money = max(0, (int) $order->total - (int) $order->prepaid_total) / (int) $order->total;
        }

        $user = User::find($order->user_id);
        $this->rules->prime(array_map(fn ($line) => (int) $line['product_id'], $lines));

        // `extra` — whole points a promotion gave this order (promotion id →
        // points): the order's own, so they wait, are credited and taken back
        // with the rest, but no discount or multiplier touches them.
        $terms = ['group' => (float) ($kept['group'] ?? $this->rules->groupMultiplier($user)), 'lines' => [], 'extra' => (array) ($kept['extra'] ?? [])];
        $sum = 0.0;
        $inside = 0;

        foreach ($lines as $line) {
            $id = (int) $line['product_id'];
            $qty = max(1, (int) ($line['qty'] ?? 1));
            $total = (int) ($line['line_total'] ?? 0);
            $inside += $this->rules->outside($id) ? 0 : $total;
            $by = $terms['lines'][$id] ?? $kept['lines'][$id] ?? null;

            if (! is_array($by) || ! isset($by['mode'])) {
                $found = $this->rules->earn($id, intdiv($total, $qty), $user);
                $by = ['mode' => $found['mode'], 'value' => $found['value']];
            }

            $terms['lines'][$id] = $by;
            $sum += $this->points($by, $total, $qty);
        }

        // What the way of paying adds. An order planned before keeps its
        // answer — "nothing" too — until it is paid another way.
        $methodId = (int) $order->payment_method_id;
        $pay = $kept['payment'] ?? (isset($kept['lines']) ? ['method' => $methodId, 'mode' => 'none', 'value' => 0.0] : null);

        if (! is_array($pay) || (int) ($pay['method'] ?? 0) !== $methodId) {
            $pay = $this->rules->payment($methodId);
        }

        $pay['points'] = $this->forPayment($pay, (int) round($inside * $afterDiscount * $money));
        $terms['payment'] = $pay;

        return [(int) floor($sum * $terms['group'] * $afterDiscount * $money) + (int) array_sum($terms['extra']) + $pay['points'], $terms];
    }

    /**
     * Points a way of paying adds to goods worth `$goods` (minor units, after
     * every discount; goods outside the programme are not among them). An
     * order with none gets nothing: a gift card bought is money changed for
     * money, however it was paid.
     *
     * @param  array{mode: string, value: float|int}  $pay
     */
    public function forPayment(array $pay, int $goods): int
    {
        if ($goods <= 0) {
            return 0;
        }

        return match ($pay['mode'] ?? 'none') {
            'fixed' => max(0, (int) round((float) $pay['value'])),
            'percent' => (int) floor($goods / 100 * (float) $pay['value'] / 100),
            default => 0,
        };
    }
}
