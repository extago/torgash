<?php

namespace Torgash\Giftcards;

use Torgash\Checkout\Models\Order;
use Torgash\Giftcards\Models\Card;

/**
 * The end of a card's money, written down.
 *
 * An expired card is already unusable by its date alone (Card::state()); the
 * nightly sweep writes the `expire` line so the balance column — and every
 * total of "money still owed to customers on cards" — stops counting it. A
 * refunded sale is the other end: the card it bought is cancelled, keeping
 * whatever was already spent spent.
 */
class Expiry
{
    public function __construct(protected Ledger $ledger) {}

    public function sweep(): int
    {
        $count = 0;

        Card::where('balance', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->chunkById(200, function ($cards) use (&$count) {
                foreach ($cards as $card) {
                    $count += (int) ($this->ledger->clear($card, 'expire') > 0);
                }
            });

        return $count;
    }

    public function voidSoldBy(Order $order): int
    {
        $count = 0;

        foreach (Card::where('order_id', $order->id)->where('balance', '>', 0)->get() as $card) {
            $this->ledger->clear($card, 'void', __('The order that bought it was refunded'));
            $card->forceFill(['status' => Card::DISABLED])->save();
            $count++;
        }

        return $count;
    }
}
