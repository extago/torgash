<?php

namespace Torgash\Giftcards;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Torgash\Giftcards\Models\Card;
use Torgash\Giftcards\Models\LedgerEntry;

/**
 * The only way a card's balance moves.
 *
 * Every movement is a signed line, and the balance on the card is rewritten as
 * the SUM of its lines in the same transaction — never incremented, because an
 * increment that raced another is a balance that disagrees with its history
 * for ever, and the history is the thing a customer disputing a charge is
 * shown.
 *
 * Spending locks the card's row (`FOR UPDATE`) before reading the balance: two
 * orders placed in the same second with one card would otherwise both see the
 * whole balance and both spend it.
 */
class Ledger
{
    public function issue(Card $card, int $amount, ?string $note = null): LedgerEntry
    {
        return $this->write($card, 'issue', abs($amount), note: $note);
    }

    /**
     * Take up to `$amount` for an order; returns what was actually taken,
     * which is less when the balance is — the caller decides whether that is
     * acceptable.
     */
    public function redeem(Card $card, int $amount, int $orderId): int
    {
        return DB::transaction(function () use ($card, $amount, $orderId) {
            $locked = Card::whereKey($card->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isUsable()) {
                return 0;
            }

            $take = min(max(0, $amount), (int) $locked->balance);

            if ($take > 0) {
                $this->write($locked, 'redeem', -$take, orderId: $orderId);
                $card->setRawAttributes($locked->getAttributes(), true);
            }

            return $take;
        });
    }

    /**
     * Put money back from an order — never more than that order took from this
     * card, net of what was already put back: a refund pressed twice must not
     * mint money.
     */
    public function refund(Card $card, int $amount, int $orderId, ?string $note = null): int
    {
        return DB::transaction(function () use ($card, $amount, $orderId, $note) {
            $locked = Card::whereKey($card->id)->lockForUpdate()->first();
            $spent = -(int) LedgerEntry::where('card_id', $card->id)->where('order_id', $orderId)
                ->whereIn('type', ['redeem', 'refund'])->sum('amount');
            $give = min(max(0, $amount), max(0, $spent));

            if ($locked && $give > 0) {
                $this->write($locked, 'refund', $give, orderId: $orderId, note: $note);
                $card->setRawAttributes($locked->getAttributes(), true);
            }

            return $give;
        });
    }

    /** A person's correction, with the reason the audit and the customer will read. */
    public function adjust(Card $card, int $delta, string $reason): LedgerEntry
    {
        if ($delta === 0) {
            throw new InvalidArgumentException('An adjustment of nothing.');
        }

        return DB::transaction(function () use ($card, $delta, $reason) {
            $locked = Card::whereKey($card->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->balance + $delta < 0) {
                throw new InvalidArgumentException('A balance cannot go below zero.');
            }

            $entry = $this->write($locked, 'adjust', $delta, note: $reason);
            $card->setRawAttributes($locked->getAttributes(), true);

            return $entry;
        });
    }

    /**
     * Whatever is left, gone — an expiry or a cancellation (`void`: a refunded
     * sale). Writes nothing for a card with nothing on it.
     */
    public function clear(Card $card, string $type, ?string $note = null): int
    {
        return DB::transaction(function () use ($card, $type, $note) {
            $locked = Card::whereKey($card->id)->lockForUpdate()->firstOrFail();
            $left = (int) $locked->balance;

            if ($left > 0) {
                $this->write($locked, $type, -$left, note: $note);
                $card->setRawAttributes($locked->getAttributes(), true);
            }

            return $left;
        });
    }

    /** The balance the ledger says, whatever the column says. */
    public static function sum(int $cardId): int
    {
        return (int) LedgerEntry::where('card_id', $cardId)->sum('amount');
    }

    protected function write(Card $card, string $type, int $amount, ?int $orderId = null, ?string $note = null): LedgerEntry
    {
        return DB::transaction(function () use ($card, $type, $amount, $orderId, $note) {
            $user = auth()->user();

            $entry = LedgerEntry::create([
                'card_id' => $card->id,
                'type' => $type,
                'amount' => $amount,
                'order_id' => $orderId,
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'note' => $note,
            ]);

            $card->forceFill(['balance' => self::sum($card->id)])->save();

            return $entry;
        });
    }
}
