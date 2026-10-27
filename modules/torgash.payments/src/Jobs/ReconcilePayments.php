<?php

namespace Torgash\Payments\Jobs;

use App\Core\Tasks\TrackedJob;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\Models\Payment;
use Torgash\Payments\PaymentActions;

/**
 * Ask the gateways about every payment we are still waiting on.
 *
 * A callback is the fast path, not the only one. It goes missing for reasons a
 * shop cannot control — the shop was down for a minute, a proxy ate the request,
 * the invoice expired and nobody was told, or (the whole of local development)
 * the address the bank was given is not reachable from the internet at all.
 * Without this, those orders stay "awaiting payment" for ever while the money is
 * sitting in the account.
 *
 * The reverse of the callback in every way that matters: WE start it, so there
 * is no signature to check, and the answer is the bank's own current view rather
 * than a message that may be a redelivery. It still goes through PaymentApplier,
 * so an answer that arrives out of order is refused here exactly as it is there.
 *
 * A TrackedJob rather than a plain one — unlike a rule's webhook, this is a
 * batch that can take minutes and its progress is worth watching, and
 * `startIfIdle()` keeps the scheduler from stacking a second pass on one that is
 * still walking the list.
 */
class ReconcilePayments extends TrackedJob
{
    /** One API call per order, so a run is capped and the next one continues. */
    public const BATCH = 200;

    public function label(): string
    {
        return 'Check pending payments';
    }

    public function module(): ?string
    {
        return 'torgash.payments';
    }

    protected function run(): void
    {
        $actions = app(PaymentActions::class);

        // Payment by payment (PLAN §40.10/4): an order whose deposit arrived
        // is partly paid, and the invoice for its rest is still worth asking
        // about — reading only the order's state skipped exactly that one.
        $payments = $this->pending();
        $this->total($payments->count());

        $done = 0;
        $moved = 0;

        foreach ($payments as $payment) {
            if (in_array('refresh', $actions->available($payment), true)) {
                $before = [$payment->state, $payment->amount];
                $event = $actions->act($payment, 'refresh');

                if ($event && [$payment->fresh()->state, $payment->fresh()->amount] !== $before) {
                    $moved++;
                }
            }

            $this->progress(++$done);
        }

        $this->result(
            ['checked' => $done, 'moved' => $moved],
            'Checked :checked, updated :moved.',
            ['checked' => $done, 'moved' => $moved],
        );
    }

    /** Payments still waiting or held, begun within the window. */
    protected function pending()
    {
        return self::waiting()->with('order')->orderBy('id')->limit(self::BATCH)->get();
    }

    /**
     * Whether a pass would have anything to ask about. The scheduler asks
     * first, so a shop with nothing pending does not collect a "checked 0"
     * task every fifteen minutes.
     */
    public static function anyPending(): bool
    {
        return self::waiting()->exists();
    }

    protected static function waiting()
    {
        $hours = max(1, (int) setting('torgash.payments', 'reconcile_hours'));

        return Payment::query()
            ->whereNotNull('external_id')
            ->whereIn('state', [PaymentState::PENDING, PaymentState::AUTHORIZED])
            ->where('created_at', '>=', now()->subHours($hours));
    }
}
