<?php

namespace Torgash\Checkout\Orders;

use App\Models\User;
use Torgash\Checkout\Models\Coupon;
use Torgash\Checkout\Models\Order;

/**
 * An order's discount as TERMS, recomputed from what the order holds now.
 *
 * Until this, the coupon's discount was the sum worked out at checkout and
 * frozen onto the row: a manager taking one laptop out of three left the
 * customer ten percent off three. Now the order carries the coupon's terms
 * (`coupon_terms`) and the manager's own discount (`manual_discount`), and
 * every recalculation (OrderTotals) asks this class what they come to.
 * `discount_amount` stays the one sum — totals, 1C, taxes and the API read it
 * and need not know there are two parts.
 *
 * Order of the two parts, as in a basket: the coupon first, on the goods; the
 * manager's discount after it, on what the coupon left.
 *
 * The coupon's base on an order is its subtotal minus what the coupon did NOT
 * reach at checkout (`excluded` — a kit's goods, which torgash.kits takes out
 * of the coupon's base through `checkout.coupon.base`). An order has no basket
 * to ask, so that amount is frozen and kept out as the other lines change.
 * On a coupon applied to an already placed order it is asked of
 * `checkout.order.coupon_base`, answered by nothing yet — the whole subtotal.
 *
 * WHICH goods the coupon covers is fixed too (PLAN §40.10/3): `lines` in the
 * terms is each line's key and the quantity the coupon was agreed on. A line
 * taken out or cut down takes its share of the base with it; a line or units
 * ADDED after placement (the customer rings about a packet of screws for the
 * computer bought on a coupon) are outside it at full price, until a manager
 * puts the coupon on the order again — that act covers everything it holds
 * then. Terms without `lines` (placed before this) cover the whole subtotal.
 *
 * The coupon's own rules (dates, the usage limit, the minimum) decide whether
 * a CUSTOMER may use it; a manager applying one to a placed order is deciding
 * that themselves, so those are not enforced here — only reported, in the
 * order's history. A disabled or deleted coupon cannot be applied at all.
 */
class OrderDiscounts
{
    /**
     * @param  list<array<string, mixed>>|null  $items  the lines it covers, as they are now
     * @return array{type: string, value: int, min_subtotal: int|null, excluded: int, lines?: array<string, int>}
     */
    public static function termsOf(Coupon $coupon, int $excluded = 0, ?array $items = null): array
    {
        $terms = [
            'type' => $coupon->type,
            'value' => (int) $coupon->value,
            'min_subtotal' => $coupon->min_subtotal !== null ? (int) $coupon->min_subtotal : null,
            'excluded' => max(0, $excluded),
        ];

        if ($items !== null) {
            $terms['lines'] = self::quantities($items);
        }

        return $terms;
    }

    /**
     * Each line's key and quantity — what a discount fixed on an order was
     * agreed on. A line stored before keys existed is named by its product,
     * which is how the order editor addresses it too.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    public static function quantities(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $key = self::keyOf($item);
            $lines[$key] = ($lines[$key] ?? 0) + (int) ($item['qty'] ?? 0);
        }

        return $lines;
    }

    /** @param  array<string, mixed>  $item */
    public static function keyOf(array $item): string
    {
        return (string) ($item['key'] ?? $item['product_id'] ?? '');
    }

    /**
     * The part of the order's goods these fixed quantities still cover: each
     * line's total in the share of its units that were agreed on — never more
     * than all of it, so more units of it do not widen the base.
     *
     * @param  array<string, int>  $covered
     */
    public static function coveredTotal(Order $order, array $covered): int
    {
        $total = 0;
        foreach ((array) $order->items as $item) {
            $qty = (int) ($item['qty'] ?? 0);
            $agreed = (int) ($covered[self::keyOf($item)] ?? 0);
            if ($qty > 0 && $agreed > 0) {
                $total += intdiv((int) ($item['line_total'] ?? 0) * min($qty, $agreed), $qty);
            }
        }

        return $total;
    }

    /**
     * The discount this order's parts come to, written onto it (not saved):
     * `discount_amount` and the manager's part's own `amount`.
     */
    public function apply(Order $order): void
    {
        $subtotal = (int) $order->subtotal;
        $coupon = $this->couponPart($order, $subtotal);

        $manual = $order->manual_discount;
        if (is_array($manual)) {
            $manual['amount'] = self::compute($manual['type'] ?? 'fixed', (int) ($manual['value'] ?? 0), max(0, $subtotal - $coupon));
            $order->manual_discount = $manual;
        }

        // What other modules took off (bonus points) was spent, not
        // computed: it stays what it was — only never more than is left. A
        // discount that came with a merged order's lines (its coupon, its
        // manager's discount) carries its TERMS and the lines they were agreed
        // on, and is worked out like a coupon on those lines (§40.10/4).
        $left = max(0, $subtotal - $coupon - (int) ($manual['amount'] ?? 0));
        $others = (array) $order->other_discounts;
        foreach ($others as $i => $other) {
            $amount = is_array($other['terms'] ?? null)
                ? self::compute(
                    (string) ($other['terms']['type'] ?? 'fixed'),
                    (int) ($other['terms']['value'] ?? 0),
                    max(0, self::coveredTotal($order, (array) ($other['terms']['lines'] ?? [])) - (int) ($other['terms']['excluded'] ?? 0)),
                )
                : (int) ($other['amount'] ?? 0);
            $others[$i]['amount'] = min($left, $amount);
            $left -= $others[$i]['amount'];
        }
        if ($others !== []) {
            $order->other_discounts = $others;
        }

        $order->discount_amount = $coupon + (int) ($manual['amount'] ?? 0) + array_sum(array_column($others, 'amount'));
    }

    /**
     * The coupon's part. With terms, from them; an order placed before terms
     * existed keeps the sum it was placed with — nobody can say what its coupon
     * said then.
     */
    public function couponPart(Order $order, int $subtotal): int
    {
        $terms = $order->coupon_terms;

        if (! is_array($terms)) {
            return max(0, (int) $order->discount_amount - $order->manualDiscount() - $order->otherDiscount());
        }

        $goods = is_array($terms['lines'] ?? null) ? min($subtotal, self::coveredTotal($order, $terms['lines'])) : $subtotal;
        $base = max(0, $goods - (int) ($terms['excluded'] ?? 0));

        if (($terms['min_subtotal'] ?? null) !== null && $base < (int) $terms['min_subtotal']) {
            return 0;
        }

        return self::compute((string) ($terms['type'] ?? 'percent'), (int) ($terms['value'] ?? 0), $base);
    }

    /** A percent of the base (value 0–100) or an amount, never more than the base. */
    public static function compute(string $type, int $value, int $base): int
    {
        $amount = $type === 'percent' ? round_money($base * min(100, max(0, $value)) / 100) : max(0, $value);

        return min($amount, max(0, $base));
    }

    /**
     * Put a coupon on a placed order (replacing one it had). Counts the use and
     * gives the replaced coupon its use back. Returns what the history should
     * say about the coupon's own rules, which a manager may overrule.
     *
     * @return list<string> notes for the order's history
     */
    public function useCoupon(Order $order, Coupon $coupon): array
    {
        $notes = [];
        if ($coupon->expires_at?->isPast()) {
            $notes[] = 'expired';
        }
        if ($coupon->starts_at?->isFuture()) {
            $notes[] = 'not started';
        }
        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            $notes[] = 'used up';
        }

        $this->releaseCoupon($order);

        $excluded = max(0, (int) $order->subtotal - (int) hook_filter('checkout.order.coupon_base', (int) $order->subtotal, ['order' => $order]));

        $order->coupon_code = $coupon->code;
        // Put on by hand, it covers what the order holds NOW — lines added
        // since placement included: this is the manager's explicit act.
        $order->coupon_terms = self::termsOf($coupon, $excluded, (array) $order->items);
        $coupon->increment('used_count');

        return $notes;
    }

    /** Take the coupon off, giving it its use back. */
    public function releaseCoupon(Order $order): void
    {
        if ($order->coupon_code) {
            $previous = Coupon::where('code', $order->coupon_code)->first();
            if ($previous && $previous->used_count > 0) {
                $previous->decrement('used_count');
            }
        }

        $order->coupon_code = null;
        $order->coupon_terms = null;
        // An order placed before terms existed carried its coupon as a sum;
        // taking the coupon off takes that sum off too.
        $order->discount_amount = $order->manualDiscount() + $order->otherDiscount();
    }

    /** The manager's own discount: a percent or an amount, and why. */
    public function setManual(Order $order, string $type, int $value, string $reason, ?User $by): void
    {
        $this->clearManual($order);
        $order->manual_discount = [
            'type' => $type === 'percent' ? 'percent' : 'fixed',
            'value' => $type === 'percent' ? min(100, max(0, $value)) : max(0, $value),
            'reason' => $reason,
            'user_id' => $by?->id,
            'by' => $by?->name,
            'amount' => 0,
        ];
    }

    /**
     * Take the manager's discount off — its amount leaves `discount_amount`
     * first, so an order whose coupon is only a frozen sum (placed before
     * terms) does not read the old manual part as coupon.
     */
    public function clearManual(Order $order): void
    {
        $order->discount_amount = max(0, (int) $order->discount_amount - $order->manualDiscount());
        $order->manual_discount = null;
    }
}
