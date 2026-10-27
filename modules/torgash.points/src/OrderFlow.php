<?php

namespace Torgash\Points;

use App\Core\Mail\Letters;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Points\Mail\PointsLetters;
use Torgash\Points\Models\OrderPoints;

/**
 * What happens to points around one order, in the order it happens:
 *
 * 1. placed — the points on its discount line are SPENT inside the order's
 *    transaction (a balance that moved refuses the order), and what it will
 *    earn is worked out and shown as waiting;
 * 2. its contents edited — the waiting number follows them;
 * 3. it reaches a status the shop chose — the points are credited, once;
 * 4. it is cancelled — what it earned is taken back and what was spent on it
 *    is returned; refunded — its share of the earned points goes.
 */
class OrderFlow
{
    public function __construct(protected Ledger $ledger, protected Earning $earning) {}

    /** Inside PlaceOrder's transaction. */
    public function placing(Order $order): void
    {
        if (! $order->user_id) {
            return;
        }

        $line = collect((array) $order->other_discounts)->firstWhere('code', 'points');
        $points = (int) ($line['points'] ?? 0);

        if ($points > 0) {
            $this->ledger->spend($order->user_id, $points, $order->id);
        }

        OrderPoints::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'spent' => $points,
            'spent_money' => (int) ($line['amount'] ?? 0),
        ]);
    }

    /** After placement (the prepaid part is known by now): what it will earn. */
    public function plan(Order $order): void
    {
        $row = OrderPoints::find($order->id);

        if ($row && ! $row->credited_at) {
            $row->update(['earn' => $this->earning->forOrder($order->fresh())]);
        }
    }

    public function statusChanged(Order $order, string $status): void
    {
        $row = OrderPoints::find($order->id);

        if (! $row) {
            return;
        }

        if (in_array($status, (array) setting('torgash.points', 'revoke_statuses', []), true)) {
            $this->undo($order, $row, __('Order cancelled'));

            return;
        }

        if (! $row->credited_at && $row->earn > 0 && in_array($status, (array) setting('torgash.points', 'earn_statuses', []), true)) {
            $this->ledger->earn($row->user_id, $row->earn, $order->id);
            $row->update(['earned' => $row->earn, 'credited_at' => now()]);

            OrderLog::record($order, 'Bonus points credited: :points', ['points' => $row->earn], by: null);

            if ($order->email) {
                Letters::send(PointsLetters::CREDITED, $order->email, ['order' => $order], $order->locale ?? config('app.locale'));
            }
        }
    }

    /** Money went back: the points it earned follow it. */
    public function changed(Order $order): void
    {
        $row = OrderPoints::find($order->id);

        if (! $row || ! in_array($order->payment_state, [PaymentState::REFUNDED, PaymentState::PARTIALLY_REFUNDED], true)) {
            return;
        }

        $share = $order->payment_state === PaymentState::REFUNDED || (int) $order->total <= 0
            ? 0.0
            : max(0, (int) $order->paid_total) / (int) $order->total;
        $keep = (int) floor($row->earn * min(1.0, $share));

        if ($row->credited_at && $row->earned > $keep) {
            $taken = $this->ledger->revoke($row->user_id, $row->earned - $keep, $order->id, __('Money returned'));
            $row->update(['earned' => $row->earned - $taken]);
        } elseif (! $row->credited_at && $row->earn > $keep) {
            $row->update(['earn' => $keep]);
        }
    }

    /** A manager edited the contents: the waiting number follows (credited ones are history). */
    public function recalculated(Order $order): void
    {
        $this->plan($order);
    }

    /** A manager's own number for what this order earns. */
    public function setEarn(Order $order, int $points): void
    {
        $row = OrderPoints::firstOrCreate(['order_id' => $order->id], ['user_id' => $order->user_id]);

        if ($row->credited_at) {
            $delta = $points - $row->earned;

            if ($delta > 0) {
                $this->ledger->earn($row->user_id, $delta, $order->id);
            } elseif ($delta < 0) {
                $delta = -$this->ledger->revoke($row->user_id, -$delta, $order->id, __('Corrected by hand'));
            }

            $row->update(['earn' => $points, 'earned' => $row->earned + $delta]);
        } else {
            $row->update(['earn' => max(0, $points)]);
        }

        OrderLog::record($order, 'Bonus points to earn set to :points', ['points' => $points]);
    }

    protected function undo(Order $order, OrderPoints $row, string $why): void
    {
        if ($row->credited_at && $row->earned > 0) {
            $taken = $this->ledger->revoke($row->user_id, $row->earned, $order->id, $why);
            $row->earned -= $taken;
        }

        $returned = (int) -\Torgash\Points\Models\Entry::where('order_id', $order->id)->where('user_id', $row->user_id)
            ->whereIn('type', ['spend', 'refund'])->sum('points');

        if ($returned > 0) {
            $this->ledger->refund($row->user_id, $returned, $order->id, $why);
        }

        $row->earn = 0;
        $row->save();
    }
}
