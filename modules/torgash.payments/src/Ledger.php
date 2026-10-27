<?php

namespace Torgash\Payments;

use Illuminate\Support\Collection;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\Models\Payment;
use Torgash\Payments\Models\Transaction;

/**
 * An order's payments, and what they come to together.
 *
 * The one place that answers "which payment is this message about" and "what
 * does the order hold now", so a gateway's callback, a button on the order
 * screen and the nightly check can never add the money up in three ways. A
 * gateway still speaks about ONE payment (its `amount` is what that invoice
 * holds after the event); the order's gateway money is the sum over all of
 * them, and its state is read from all of them (`state()`).
 */
class Ledger
{
    /**
     * The payment a gateway's event is about.
     *
     * By the gateway's own id first. An id nobody has seen yet takes over an
     * open payment of the same order the gateway has never answered about —
     * that is a payment which was started under one name and reported under
     * another (a Stripe session becoming its PaymentIntent). With no id at all
     * the event is about the latest payment. Otherwise it is a new payment.
     */
    public function forEvent(string $gateway, Order $order, PaymentEvent $event): Payment
    {
        if ($event->externalId !== null) {
            $known = Payment::where('gateway', $gateway)->where('external_id', $event->externalId)->first();
            if ($known) {
                return $known;
            }

            $renamed = Payment::where('gateway', $gateway)
                ->where('order_id', $order->id)
                ->where('state', PaymentState::PENDING)
                ->where('amount', 0)
                ->whereNull('started_as')
                ->latest('id')
                ->get()
                ->first(fn (Payment $payment) => ! $payment->heardFrom());

            if ($renamed) {
                $renamed->update(['started_as' => $renamed->external_id, 'external_id' => $event->externalId]);

                return $renamed;
            }
        } else {
            $latest = Payment::where('gateway', $gateway)->where('order_id', $order->id)->latest('id')->first();
            if ($latest) {
                return $latest;
            }
        }

        return Payment::create([
            'order_id' => $order->id,
            'gateway' => $gateway,
            'external_id' => $event->externalId,
            'state' => PaymentState::PENDING,
        ]);
    }

    /**
     * A gateway wrote down that it began a payment (the row every gateway
     * writes when it makes an invoice): that is a payment, waiting.
     */
    public function started(Transaction $transaction): void
    {
        if (! $transaction->order_id || $transaction->external_id === null || $transaction->payment_id) {
            return;
        }

        // Begun when the gateway wrote it down, which is what the nightly
        // check's window is measured from.
        $payment = Payment::firstOrCreate(
            ['gateway' => $transaction->gateway, 'external_id' => $transaction->external_id],
            [
                'order_id' => $transaction->order_id,
                'state' => $transaction->state ?? PaymentState::PENDING,
                'requested' => (int) $transaction->amount,
                'amount' => PaymentState::holdsMoney($transaction->state) ? (int) $transaction->amount : 0,
                'created_at' => $transaction->created_at ?? now(),
            ],
        );

        $transaction->forceFill(['payment_id' => $payment->id])->saveQuietly();
    }

    /**
     * The order's payments, and those of every order poured into it (PLAN
     * §40.10/4): a payment stays the payment of the order the bank knows it
     * by, but its money is now this order's.
     *
     * @return Collection<int, Payment> oldest first
     */
    public function of(Order $order): Collection
    {
        return Payment::whereIn('order_id', $order->mergedIds())->orderBy('id')->get();
    }

    /**
     * The order's payment columns worked out again from its payments — after
     * a merge moved money between orders. Nothing to do for an order no
     * gateway has taken money for: its columns are whatever a person or a
     * gift card said.
     */
    public function resettle(Order $order): void
    {
        $payments = $this->of($order);

        if ($payments->isEmpty()) {
            return;
        }

        $order->update(\Torgash\Checkout\Orders\Settlement::afterReport($order, $this->state($payments), $this->held($payments)));
    }

    /**
     * What the payments come to, as the order's own state: a hold anywhere is
     * a hold (the manager has something to capture or release), money that
     * went back from any of them is a partial refund while some remains, and
     * with nothing held the state is "refunded" if anything ever was, else
     * whatever the payment that just spoke said.
     *
     * @param  Collection<int, Payment>  $payments
     */
    public function state(Collection $payments, ?Payment $latest = null): string
    {
        $holding = $payments->filter(fn (Payment $payment) => $payment->held() > 0);
        $returned = $payments->contains(fn (Payment $payment) => in_array($payment->state, [PaymentState::PARTIALLY_REFUNDED, PaymentState::REFUNDED], true));

        if ($holding->isEmpty()) {
            return $returned ? PaymentState::REFUNDED : ($latest ?? $payments->last())?->state ?? PaymentState::PENDING;
        }

        return match (true) {
            $holding->contains(fn (Payment $payment) => $payment->isHold()) => PaymentState::AUTHORIZED,
            $returned => PaymentState::PARTIALLY_REFUNDED,
            default => PaymentState::PAID,
        };
    }

    /** @param  Collection<int, Payment>  $payments */
    public function held(Collection $payments): int
    {
        return (int) $payments->sum(fn (Payment $payment) => $payment->held());
    }

    /**
     * Where a refund of the gateway part comes from, in order: holds first —
     * letting one go costs nobody anything — then what was taken, newest first.
     *
     * @return Collection<int, Payment>
     */
    public function refundOrder(Order $order): Collection
    {
        return $this->of($order)
            ->filter(fn (Payment $payment) => $payment->held() > 0)
            ->sortBy(fn (Payment $payment) => [$payment->isHold() ? 0 : 1, -$payment->id])
            ->values();
    }
}
