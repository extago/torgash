<?php

namespace Torgash\Checkout\Orders;

use App\Core\Support\Currencies;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;

/**
 * What the bank will be asked for, and when that answer stops being able to
 * change.
 *
 * The rule is one sentence — a payment method that cannot take the currency an
 * order was quoted in charges in its own — but it is asked at three different
 * moments, and getting them to agree is the whole job:
 *
 *   at CHECKOUT, from the method the customer just picked, and frozen onto the
 *     order there and then (`columns()`);
 *   on a PAGE, for an order that has nothing frozen — every order placed before
 *     this existed, and every one placed before the shop configured its methods.
 *     Answered live, at today's rate, so the Pay button and the line beside the
 *     total say what the bank is about to be asked for rather than an amount no
 *     acquirer would accept (`forOrder()`);
 *   at PAYMENT, where the live answer is written down before anybody is charged
 *     and never moves again (`freeze()`).
 *
 * That last one is what keeps "frozen" honest. The number does not have to be
 * decided when the order is placed; it has to stop moving before the customer
 * is charged, so that tomorrow's reprint matches today's receipt.
 */
class PaymentCurrency
{
    /**
     * The columns to store for an order quoted in `$quoted` and paid by
     * `$method` — empty when the method takes that currency itself, which is
     * the ordinary case and writes nothing down.
     *
     * @return array<string, mixed>
     */
    public function columns(PaymentMethod $method, string $quoted): array
    {
        $charge = $method->chargeCurrencyFor($quoted);

        if ($charge === null || $charge === $quoted) {
            return [];
        }

        $rate = (float) (app(Currencies::class)->find($charge)?->rate ?? 0);

        return $rate > 0 ? ['payment_currency' => $charge, 'payment_rate' => $rate] : [];
    }

    /**
     * What this order is charged in: what was frozen onto it, or — when nothing
     * was — what the method it was sold under would charge today.
     *
     * @return array{currency: string, rate: float}
     */
    public function forOrder(Order $order): array
    {
        if ($order->payment_currency) {
            return [
                'currency' => (string) $order->payment_currency,
                'rate' => (float) ($order->payment_rate ?: 1),
            ];
        }

        $quoted = ['currency' => $order->chargeCurrency(), 'rate' => $order->chargeRate()];
        $method = PaymentMethod::forOrder($order);

        if (! $method) {
            return $quoted;
        }

        $columns = $this->columns($method, $quoted['currency']);

        return $columns === []
            ? $quoted
            : ['currency' => $columns['payment_currency'], 'rate' => (float) $columns['payment_rate']];
    }

    /**
     * Write today's answer down, so it is not tomorrow's.
     *
     * Called on the way into a gateway. An order that already has one is left
     * alone even if the shop has since changed the method: the customer agreed
     * to an amount, and a gateway that can no longer take it says so in its own
     * words rather than having the agreement rewritten under it.
     */
    public function freeze(Order $order): void
    {
        if ($order->payment_currency) {
            return;
        }

        $resolved = $this->forOrder($order);

        if ($resolved['currency'] === $order->chargeCurrency()) {
            return;
        }

        $order->forceFill([
            'payment_currency' => $resolved['currency'],
            'payment_rate' => $resolved['rate'],
        ])->save();
    }
}
