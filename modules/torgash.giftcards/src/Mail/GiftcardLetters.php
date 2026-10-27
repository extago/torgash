<?php

namespace Torgash\Giftcards\Mail;

use Illuminate\Support\Facades\Route;
use Torgash\Checkout\Models\Order;
use Torgash\Giftcards\Models\Card;

/**
 * The gift cards' letters in core's registry (PLAN §41):
 *
 * - `giftcards.received` — to whoever the card is FOR, on the day the buyer
 *   chose (or at once): who sent it, their words, the amount, the code and
 *   the card's own page;
 * - `giftcards.purchased` — to the BUYER once their order is paid: every card
 *   it bought, each with its page, so a card bought for oneself or to be
 *   printed and handed over is in the inbox too.
 *
 * Money is the shop's books — a card holds books money whatever currency the
 * reader browses in — and links are in the card's own language, since a
 * worker sending on a birthday has no request to take them from.
 */
class GiftcardLetters
{
    public const RECEIVED = 'giftcards.received';

    public const PURCHASED = 'giftcards.purchased';

    /** @return array<string, array<string, mixed>> */
    public static function events(): array
    {
        return [
            self::RECEIVED => [
                'label' => 'Gift card: to the recipient',
                'group' => 'Gift cards',
                'audience' => 'customer',
                'subject' => 'A gift card for {amount} from {sender}',
                'heading' => '{sender} sent you a gift card',
                'body' => "{message}\n\nThe card is worth {amount}. To pay with it, type its code where the basket asks for a coupon:\n\n{code}\n\n{expires}",
                'button' => ['label' => 'Open the gift card', 'url' => '{card_url}'],
                'help' => self::help(),
                'tokens' => fn (array $c) => self::tokens($c['card'] ?? null),
                'sample' => fn () => ['card' => Card::latest('id')->first()],
            ],
            self::PURCHASED => [
                'label' => 'Gift card: to the buyer',
                'group' => 'Gift cards',
                'audience' => 'customer',
                'subject' => 'Your gift cards from order {order_number}',
                'heading' => 'Your gift cards are ready',
                'body' => "Thank you for your order {order_number}. Here are the gift cards it bought:\n\n{cards}\n\nA card for somebody else is sent to them on the day you chose.",
                'help' => [
                    'order_number' => 'The order number',
                    'cards' => 'The cards, each with its amount and its page (on a line of its own)',
                ],
                'tokens' => fn (array $c) => ['order_number' => (string) (($c['order'] ?? null)?->order_number ?? '')],
                'blocks' => fn (array $c) => ['cards' => self::cardsBlock($c['order'] ?? null)],
                'sample' => fn () => ['order' => Order::whereIn('id', Card::whereNotNull('order_id')->select('order_id'))->latest('id')->first()],
            ],
        ];
    }

    /** @return array<string, string> */
    protected static function help(): array
    {
        return [
            'amount' => 'The card\'s amount',
            'balance' => 'What is left on it',
            'sender' => 'Who sends it',
            'recipient' => 'Whom it is for',
            'message' => 'The sender\'s words',
            'code' => 'The code',
            'expires' => 'Until when it can be used (a sentence, or nothing)',
            'card_url' => 'The card\'s own page',
        ];
    }

    /** @return array<string, string> */
    protected static function tokens(?Card $card): array
    {
        if (! $card) {
            return [];
        }

        return [
            'amount' => format_price((int) $card->initial_amount, shop_currency()),
            'balance' => format_price((int) $card->balance, shop_currency()),
            'sender' => (string) ($card->sender_name ?: current_storefront()?->name ?: config('app.name')),
            'recipient' => (string) ($card->recipient_name ?? ''),
            'message' => (string) ($card->message ?? ''),
            'code' => $card->formattedCode(),
            'expires' => $card->expires_at ? __('It can be used until :date.', ['date' => $card->expires_at->format('d.m.Y')]) : '',
            'card_url' => self::url($card),
        ];
    }

    protected static function cardsBlock(?Order $order): string
    {
        if (! $order) {
            return '';
        }

        $cards = Card::where('order_id', $order->id)->orderBy('id')->get();

        return view('torgash.giftcards::mail.cards', [
            'mailCards' => $cards->map(fn (Card $card) => [
                'amount' => format_price((int) $card->initial_amount, shop_currency()),
                'masked' => $card->masked(),
                'for' => $card->recipient_name ?: $card->recipient_email,
                'on' => $card->deliver_at?->format('d.m.Y'),
                'url' => self::url($card),
            ])->all(),
        ])->render();
    }

    /** The card's page in the card's own language — a worker has no request to take it from. */
    public static function url(Card $card): string
    {
        $name = 'giftcards.show';
        $locale = $card->locale;
        $default = $card->storefront_id ? \App\Models\Storefront::find($card->storefront_id)?->default_locale : null;

        if ($locale && $locale !== ($default ?? config('app.locale')) && Route::has($locale.'.'.$name)) {
            $name = $locale.'.'.$name;
        }

        return route($name, $card->public_token);
    }
}
