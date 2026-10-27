<?php

namespace Torgash\Checkout\Invariants;

use Torgash\Checkout\Models\Coupon;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\OrderTotals;
use Torgash\Checkout\Orders\PaymentState;

/**
 * What must be true about an order, said by the module that owns orders.
 *
 * Answered lazily through `guard.invariants`, which costs nothing when
 * `torgash.guard` is not installed — nobody fires the hook, and checkout gains
 * no dependency on a module a shop may never want. The reverse arrangement,
 * where the guard knows this module's tables, is the one that breaks: it would
 * be a module reaching into another module's schema, and it would leave every
 * module nobody thought about unwatched.
 *
 * All four are things that HAVE happened to a shop rather than things that
 * could: the promotions review of 2026-09-07 found an order totalling −20.00
 * and a counter past its limit, and each was invisible until somebody went
 * looking with a query.
 */
class CheckoutInvariants
{
    /**
     * How far back the arithmetic check reads.
     *
     * It cannot be a query — the total includes whatever `extra_totals` says,
     * which is a module answering per order — so it is PHP, and PHP over every
     * order a shop has ever taken is a report rather than a watch. The most
     * recent thousand is where a new bug shows up first and where a wrong total
     * is still worth correcting.
     */
    public const SCAN = 1000;

    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            'checkout.order_amounts_not_negative' => [
                'label' => 'No order has a negative total',
                'explain' => 'A negative total means a discount or an edit took more off than the order contained. Open the order: correcting its contents recomputes the money through the same code checkout uses.',
                'check' => fn (int $limit) => Order::query()
                    ->where(fn ($q) => $q->where('total', '<', 0)->orWhere('subtotal', '<', 0))
                    ->orderByDesc('id')
                    ->limit($limit)
                    ->get()
                    ->map(fn (Order $o) => $this->violation($o, ['Total: :total', [
                        'total' => admin_price($o->total, $o->currency_code),
                    ]])),
            ],

            'checkout.order_arithmetic' => [
                'label' => 'An order\'s total is its lines, less the discount, plus delivery and taxes (the most recent 1000)',
                'explain' => 'The stored total disagrees with what the engine says it should be — something wrote the column instead of going through OrderTotals. Opening the order and saving its contents recomputes it; the number that changes is the one that was wrong.',
                'check' => fn (int $limit) => $this->arithmetic($limit),
            ],

            // Two axes, and the whole point of them being two is that they can
            // disagree. This is what noticing that they have looks like.
            'checkout.paid_orders_have_money' => [
                'label' => 'An order marked paid has an amount recorded, and an amount recorded means it is not marked unpaid',
                'explain' => 'Payment state and the amount are kept separately on purpose (an underpayment and a partial refund are both "some, not all"), which is exactly why they can drift. Mark the payment on the order screen, or record what really arrived.',
                'check' => fn (int $limit) => Order::query()
                    ->where(fn ($q) => $q
                        ->where(fn ($w) => $w->whereIn('payment_state', PaymentState::withMoney())->where('paid_total', '<=', 0))
                        ->orWhere(fn ($w) => $w->where('payment_state', PaymentState::PENDING)->where('paid_total', '>', 0)))
                    ->orderByDesc('id')
                    ->limit($limit)
                    ->get()
                    // The state goes in as its CODE, not as its label: a
                    // parameter is stored as it is given, so a word translated
                    // when the check ran would be frozen in that language while
                    // the sentence around it moved.
                    ->map(fn (Order $o) => $this->violation($o, [':state, recorded: :paid', [
                        'state' => $o->payment_state,
                        'paid' => admin_price($o->paid_total, $o->currency_code),
                    ]])),
            ],

            'checkout.coupon_within_limit' => [
                'label' => 'No coupon has been used more times than it allows',
                'explain' => 'The counter is past the limit, so the coupon has been accepted after it should have stopped — usually two orders placed in the same instant. Raise the limit if that is fine, or switch the coupon off.',
                'severity' => 'warning',
                'check' => fn (int $limit) => Coupon::query()
                    ->where('usage_limit', '>', 0)
                    ->whereColumn('used_count', '>', 'usage_limit')
                    ->limit($limit)
                    ->get()
                    ->map(fn (Coupon $c) => [
                        'id' => (string) $c->id,
                        'title' => $c->code,
                        'detail' => ['Used :used of :limit', ['used' => $c->used_count, 'limit' => $c->usage_limit]],
                        'url' => route('admin.checkout.coupons.index'),
                    ]),
            ],
        ];
    }

    /**
     * The arithmetic, order by order, through the very code that writes those
     * columns — `OrderTotals::subtotal()` and `::total()` are pure readers, so
     * the check is "what is stored" against "what the engine would store", and
     * there is no second formula here to drift from the first one.
     *
     * A generator, so the engine's limit stops the scan rather than filtering
     * a finished list.
     */
    protected function arithmetic(int $limit): iterable
    {
        $totals = app(OrderTotals::class);
        $found = 0;

        foreach (Order::query()->orderByDesc('id')->limit(self::SCAN)->cursor() as $order) {
            $subtotal = $totals->subtotal($order);
            $total = $totals->total($order);

            if ($subtotal === (int) $order->subtotal && $total === (int) $order->total) {
                continue;
            }

            yield $this->violation($order, ['Stored :stored, should be :expected', [
                'stored' => admin_price($order->total, $order->currency_code),
                'expected' => admin_price($total, $order->currency_code),
            ]]);

            if (++$found >= $limit) {
                return;
            }
        }
    }

    /**
     * The detail is a translation key with its parameters, never a finished
     * sentence: the nightly run speaks the application's default language and
     * whoever reads the screen may not.
     *
     * @param  array{0: string, 1: array<string, mixed>}  $detail
     * @return array<string, mixed>
     */
    protected function violation(Order $order, array $detail): array
    {
        return [
            'id' => (string) $order->id,
            'title' => $order->order_number ?: '#'.$order->id,
            'detail' => $detail,
            // The order screen is bound to the NUMBER, not the id — and the
            // number is nullable, so the id is the fallback rather than the
            // first choice.
            'url' => route('admin.orders.show', $order->order_number ?: $order->id),
        ];
    }
}
