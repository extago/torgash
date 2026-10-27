<?php

namespace Torgash\Giftcards;

use App\Core\Mail\Letters;
use Illuminate\Support\Facades\Log;
use Torgash\Checkout\Models\Order;
use Torgash\Giftcards\Mail\GiftcardLetters;
use Torgash\Giftcards\Models\Card;

/**
 * Handing a card to whoever it is for: a letter on the day the buyer chose,
 * or at once. Swept every few minutes (a birthday card has to go out on the
 * birthday with nobody pressing anything), stamped `delivered_at` so it goes
 * once — and a card switched off or still in a drawer (`pending`) is never
 * sent.
 */
class Delivery
{
    /** @return int how many letters were queued */
    public function sendDue(?iterable $cards = null): int
    {
        $cards ??= Card::query()
            ->whereNotNull('recipient_email')
            ->whereNull('delivered_at')
            ->where('status', Card::ACTIVE)
            ->where(fn ($q) => $q->whereNull('deliver_at')->orWhere('deliver_at', '<=', now()))
            ->orderBy('id')
            ->limit(200)
            ->get();

        $sent = 0;

        foreach ($cards as $card) {
            if ($card->recipient_email === null || $card->delivered_at !== null || $card->status !== Card::ACTIVE
                || ($card->deliver_at !== null && $card->deliver_at->isFuture())) {
                continue;
            }

            $sent += (int) $this->send($card);
        }

        return $sent;
    }

    /** One card's letter, now; also the admin's "send again". */
    public function send(Card $card): bool
    {
        if (! $card->recipient_email) {
            return false;
        }

        try {
            $queued = Letters::send(GiftcardLetters::RECEIVED, $card->recipient_email, ['card' => $card], $card->locale ?? config('app.locale'));
        } catch (\Throwable $e) {
            Log::error('Could not queue a gift card letter', ['card_id' => $card->id, 'error' => $e->getMessage()]);

            return false;
        }

        $card->forceFill(['delivered_at' => now()])->save();

        return $queued;
    }

    /** After an order's cards were issued: the buyer's letter, and whatever is due today. */
    public function afterSale(Order $order, array $cards): void
    {
        if ($cards === []) {
            return;
        }

        if ($order->email) {
            try {
                Letters::send(GiftcardLetters::PURCHASED, $order->email, ['order' => $order], $order->locale ?? config('app.locale'));
            } catch (\Throwable $e) {
                Log::error('Could not queue the gift card purchase letter', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        $this->sendDue($cards);
    }
}
