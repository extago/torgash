<?php

namespace Torgash\Giftcards;

use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Orders\Settlement;
use Torgash\Giftcards\Models\Card;
use Torgash\Giftcards\Models\LedgerEntry;

/**
 * Gift cards and a PLACED order: what they paid, a card put on afterwards by
 * a manager ("the customer forgot"), taken off again, and money going back
 * onto them. The ledger is the record — which card paid how much of which
 * order is the sum of its lines for that order — and `prepaid_total` on the
 * order is checkout's copy of the same sum, moved through Settlement so the
 * payment state is worked out in one place.
 */
class OrderCards
{
    public function __construct(protected Ledger $ledger, protected Rules $rules) {}

    /**
     * card id → what it has paid of this order, net of anything put back.
     *
     * @return array<int, int>
     */
    public function paid(Order $order): array
    {
        return LedgerEntry::where('order_id', $order->id)
            ->whereIn('type', ['redeem', 'refund'])
            ->groupBy('card_id')
            ->selectRaw('card_id, -sum(amount) as net')
            ->pluck('net', 'card_id')
            ->map(fn ($net) => (int) $net)
            ->filter(fn (int $net) => $net > 0)
            ->all();
    }

    /** @return list<array{label: string, amount: int}> */
    public function lines(Order $order): array
    {
        $paid = $this->paid($order);

        if ($paid === []) {
            return [];
        }

        $cards = Card::whereIn('id', array_keys($paid))->get()->keyBy('id');
        $lines = [];

        foreach ($paid as $cardId => $amount) {
            $card = $cards->get($cardId);
            $lines[] = [
                'label' => __('Gift card :card', ['card' => $card?->masked() ?? '#'.$cardId]),
                'amount' => $amount,
            ];
        }

        return $lines;
    }

    /**
     * A manager puts a card on a placed order. Pays what is still owed, within
     * the card's own rules and balance (and `$limit`, if typed); returns what
     * it paid, or a reason it could not.
     */
    public function attach(Order $order, Card $card, ?int $limit = null): int|string
    {
        if ($reason = match ($card->state()) {
            'active' => null,
            default => Card::stateLabel($card->state()),
        }) {
            return __('This gift card cannot pay: :state.', ['state' => $reason]);
        }

        if ($card->owner_user_id && $order->user_id && $card->owner_user_id !== $order->user_id) {
            return __('This gift card belongs to another account.');
        }

        $lines = array_values(array_map(fn ($line) => [
            'product_id' => (int) ($line['product_id'] ?? 0),
            'line_total' => (int) ($line['line_total'] ?? 0),
        ], array_filter((array) $order->items, fn ($line) => is_array($line) && empty($line['parent_key']))));

        $owed = max(0, (int) $order->total - (int) $order->paid_total);
        $want = min($owed, $this->rules->payable($card, $lines, (int) $order->total, (int) $order->shipping_cost), $limit ?? PHP_INT_MAX);

        if ($want <= 0) {
            return __('Nothing on this order can be paid with this card.');
        }

        return DB::transaction(function () use ($order, $card, $want) {
            $got = $this->ledger->redeem($card, $want, $order->id);

            if ($got > 0) {
                $order->update(Settlement::afterPrepaid($order, (int) $order->prepaid_total + $got));
                OrderLog::record($order, 'Paid in advance: :what (:amount)', [
                    'what' => __('Gift card :card', ['card' => $card->masked()]),
                    'amount' => format_price($got, $order->currency_code),
                ]);
            }

            return $got;
        });
    }

    /** A manager takes a card back off an order: all it paid goes back onto it. */
    public function detach(Order $order, Card $card): int
    {
        $amount = $this->paid($order)[$card->id] ?? 0;

        if ($amount <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($order, $card, $amount) {
            $given = $this->ledger->refund($card, $amount, $order->id, __('Taken off the order'));
            $order->update(Settlement::afterPrepaid($order, max(0, (int) $order->prepaid_total - $given)));
            OrderLog::record($order, 'Gift card :card taken off the order (:amount back onto it)', [
                'card' => $card->masked(),
                'amount' => format_price($given, $order->currency_code),
            ]);

            return $given;
        });
    }

    /**
     * Checkout's refund asking for `$amount` of the prepaid part back — the
     * most recently used card first, each never above what it paid. Only the
     * ledger moves here; the order's columns are checkout's to write.
     */
    public function refund(Order $order, int $amount, ?string $source = null): int
    {
        $left = $amount;
        $given = 0;
        $paid = $this->paid($order);
        krsort($paid);

        foreach ($paid as $cardId => $net) {
            if ($left <= 0) {
                break;
            }

            $card = Card::find($cardId);
            $back = $card ? $this->ledger->refund($card, min($net, $left), $order->id, $source) : 0;
            $given += $back;
            $left -= $back;
        }

        return $given;
    }
}
