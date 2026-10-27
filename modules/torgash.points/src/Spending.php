<?php

namespace Torgash\Points;

use Torgash\Cart\CartManager;
use Torgash\Checkout\CouponManager;

/**
 * Points spent on a basket (PLAN §40.5) — a DISCOUNT, after the coupon and
 * before a gift card, which is why it reaches checkout through
 * `checkout.cart.discounts` and lowers the tax base like the coupon does.
 *
 * Kept in the cart's own `checkout` answers, as the gift cards are:
 * `points` — how many the customer asked to spend (null = none), and
 * `points_lines` — the keys of lines bought outright for their price in
 * points. What they come to is worked out every time from the balance and the
 * rules, never stored: a balance that moved since the basket was filled is
 * simply a smaller discount, and PlaceOrder spends only what the line says.
 */
class Spending
{
    public const ANSWER = 'points';

    public const LINES = 'points_lines';

    public function __construct(protected Ledger $ledger, protected PointPrices $prices) {}

    public function requested(CartManager $cart): int
    {
        return max(0, (int) ($cart->checkout()[self::ANSWER] ?? 0));
    }

    /** @return list<string> */
    public function lines(CartManager $cart): array
    {
        return array_values(array_map('strval', (array) ($cart->checkout()[self::LINES] ?? [])));
    }

    public function ask(CartManager $cart, int $points): void
    {
        $cart->setCheckout([self::ANSWER => $points > 0 ? $points : null]);
    }

    public function toggleLine(CartManager $cart, string $key): void
    {
        $lines = $this->lines($cart);
        $lines = in_array($key, $lines, true) ? array_values(array_diff($lines, [$key])) : [...$lines, $key];

        $cart->setCheckout([self::LINES => $lines ?: null]);
    }

    /** Money one point takes off, in minor units. */
    public function pointValue(): int
    {
        return (int) round((float) setting('torgash.points', 'point_value', 1) * 100);
    }

    /**
     * What points take off this basket, as checkout's discount line — or
     * null. `$base` is the goods after the coupon.
     *
     * @return array<string, mixed>|null
     */
    public function quote(CartManager $cart, int $base): ?array
    {
        $user = auth()->user();

        if (! $user || $base <= 0) {
            return null;
        }

        if (! setting('torgash.points', 'with_coupon', true) && app(CouponManager::class)->current()) {
            return null;
        }

        $balance = $this->ledger->balance($user->id);
        $wanted = $this->lines($cart);
        $covered = 0;
        $linePoints = 0;
        $bought = [];

        // Lines bought outright for points, while the balance lasts.
        if ($wanted !== []) {
            foreach ($cart->items() as $item) {
                $price = $this->prices->for($item->product);

                if ($price === null || ! in_array((string) $item->key, $wanted, true)) {
                    continue;
                }

                $cost = $price * (int) $item->qty;

                if ($linePoints + $cost > $balance || $covered + (int) $item->line_total > $base) {
                    continue;
                }

                $linePoints += $cost;
                $covered += (int) $item->line_total;
                $bought[] = (string) $item->key;
            }
        }

        // Points spent as money, within the shop's share of the goods.
        $free = 0;
        $value = $this->pointValue();
        $requested = $this->requested($cart);

        if ($requested > 0 && $value > 0) {
            $share = max(0, min(100, (int) setting('torgash.points', 'spend_max_percent', 50)));
            $room = min($base - $covered, intdiv($base * $share, 100));
            $free = min($requested, $balance - $linePoints, intdiv(max(0, $room), $value));

            if ($free < max(0, (int) setting('torgash.points', 'spend_min', 0))) {
                $free = 0;
            }
        }

        $points = $linePoints + max(0, $free);
        $amount = $covered + max(0, $free) * $value;

        if ($points <= 0 || $amount <= 0) {
            return null;
        }

        return [
            'code' => 'points',
            'label' => __('Bonus points (:points)', ['points' => number_format($points, 0, '.', ' ')]),
            'amount' => $amount,
            'points' => $points,
            'lines' => $bought,
        ];
    }

    /**
     * The most a customer could spend as money on this basket right now —
     * what the box offers as its maximum.
     */
    public function maximum(CartManager $cart, int $base): int
    {
        $user = auth()->user();
        $value = $this->pointValue();

        if (! $user || $value <= 0) {
            return 0;
        }

        $share = max(0, min(100, (int) setting('torgash.points', 'spend_max_percent', 50)));

        return max(0, min($this->ledger->balance($user->id), intdiv(intdiv($base * $share, 100), $value)));
    }
}
