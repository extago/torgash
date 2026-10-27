<?php

namespace Torgash\Payments;

use Throwable;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentCurrency;
use Torgash\Payments\Models\Transaction;

/**
 * Asking a gateway to begin a payment, and dealing with it not beginning.
 *
 * One place, because there are two ways in — straight after the order is
 * placed, and the Pay link on the confirmation page — and they must fail
 * identically. They did not: the first had no error handling at all, so a
 * gateway that refused (or threw) made checkout itself return a 500 on an order
 * that was already committed.
 *
 * The rules, in one place (§48):
 *   the customer always learns that it did not start;
 *   the reason always reaches the payment log;
 *   an unexpected exception also reaches the application log, with its trace;
 *   the reason reaches the screen only for whoever may act on it.
 */
class Starter
{
    public function __construct(protected Gateways $gateways) {}

    /**
     * Where to send the customer, or null with a reason flashed for the
     * confirmation page to show.
     */
    public function start(Order $order): ?string
    {
        $found = $this->gateways->forOrder($order);

        // Nothing left for a bank to take — a gift card paid it all. Sending the
        // customer to a payment page for 0 would be a page that refuses them.
        if (! $found || $order->chargeNow() <= 0) {
            return null;
        }

        // The last moment at which what the bank is asked for may still be
        // decided, and therefore the moment it stops being able to change. An
        // order placed before the shop said which currencies its methods take
        // has nothing frozen on it; this writes today's answer down before the
        // customer is charged, so the receipt and every later reprint agree.
        // An order that already carries one is left exactly as it is.
        app(PaymentCurrency::class)->freeze($order);

        try {
            $url = $found['gateway']->start($order, Charge::for($order), $found['binding']->gatewayConfig());
        } catch (Throwable $e) {
            $this->flash($this->record($order, $found['binding']->gateway, $e));

            return null;
        }

        if ($url === null) {
            // No exception and no URL: the gateway declined without saying why.
            // Recorded all the same, so the log is never emptier than the screen.
            $this->flash($this->record($order, $found['binding']->gateway, null));
        }

        return $url;
    }

    /** @param  array<string, string>  $flash */
    protected function flash(array $flash): void
    {
        foreach ($flash as $key => $value) {
            session()->flash($key, $value);
        }
    }

    /**
     * Records why it did not start, and returns what to say.
     *
     * The customer always gets the same sentence: the reason is about the
     * shop's own setup or the bank's mood, and neither is theirs to fix.
     *
     * @return array<string, string>
     */
    protected function record(Order $order, string $gateway, ?Throwable $e): array
    {
        $known = $e instanceof PaymentFailed;
        $reason = $e
            ? $e->getMessage()
            : __('the gateway gave no payment page');

        if ($e && ! $known) {
            report($e);
        }

        Transaction::create([
            'gateway' => $gateway,
            'order_id' => $order->id,
            'state' => $order->payment_state,
            'amount' => $order->chargeNow(),
            // Verified: we know first-hand that this happened — it is our own
            // outgoing call, not an unauthenticated message from outside.
            'verified' => true,
            'applied' => false,
            'summary' => __('Payment not started: :reason', ['reason' => $reason]),
            'created_at' => now(),
        ]);

        return [
            'payment_error' => __('We could not start the payment. Please try again, or contact us if it keeps happening.'),
            'payment_error_reason' => $reason,
        ];
    }
}
