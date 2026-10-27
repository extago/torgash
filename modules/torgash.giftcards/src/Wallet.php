<?php

namespace Torgash\Giftcards;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Torgash\Cart\CartManager;
use Torgash\Checkout\Models\Order;
use Torgash\Giftcards\Models\Card;

/**
 * The gift cards on a basket, and what they pay.
 *
 * Kept in the cart's own `checkout` answers (`giftcards` → `[{id, limit}]`),
 * because the basket is where the customer is building the bill and the cart
 * already carries a bag for exactly that — this module adds no column to
 * another's table. Card IDS, never codes: the code was proven once, when it
 * was typed, and is not carried around afterwards. `limit` is the customer's
 * own "take no more than this from it" (null = as much as it can), which is
 * how somebody keeps half a card for next time.
 *
 * Several cards on one bill are spent soonest-to-expire first, so the one
 * about to be lost is the one used.
 */
class Wallet
{
    public const ANSWER = 'giftcards';

    /** How many wrong codes one address may type in a quarter of an hour. */
    public const TRIES = 10;

    public function __construct(protected Rules $rules, protected Ledger $ledger) {}

    /** @return list<array{id: int, limit: int|null}> */
    public function applied(CartManager $cart): array
    {
        return array_values(array_filter(array_map(
            fn ($row) => is_array($row) && (int) ($row['id'] ?? 0) > 0
                ? ['id' => (int) $row['id'], 'limit' => isset($row['limit']) ? max(0, (int) $row['limit']) : null]
                : null,
            (array) ($cart->checkout()[self::ANSWER] ?? []),
        )));
    }

    /**
     * A code typed into the basket's box. Answered for any string that could
     * be a card; anything else is left to the coupon's own "not valid".
     *
     * @return array{ok: bool, message: string}|null
     */
    public function applyCode(CartManager $cart, string $typed): ?array
    {
        $code = Codes::normalise($typed);

        if (! Codes::looksLikeCard($code)) {
            return null;
        }

        $key = 'giftcards:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, self::TRIES)) {
            return ['ok' => false, 'message' => __('Too many attempts. Try again in :minutes min.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)])];
        }

        $card = Card::where('code_hash', Codes::hash($code))->first();

        if (! $card) {
            RateLimiter::hit($key, 900);
            // A code that names nothing is the one act here with no innocent
            // reading at volume; whoever counts such things (torgash.guard)
            // hears of it without this module knowing it exists.
            hook_action('core.code.failed', ['kind' => 'giftcard', 'request' => request()]);

            return ['ok' => false, 'message' => __('There is no gift card with this code.')];
        }

        return $this->attach($cart, $card);
    }

    /** @return array{ok: bool, message: string} */
    public function attach(CartManager $cart, Card $card): array
    {
        if ($refusal = $this->refusal($card)) {
            return ['ok' => false, 'message' => $refusal];
        }

        $rows = $this->applied($cart);

        if (! in_array($card->id, array_column($rows, 'id'), true)) {
            $rows[] = ['id' => $card->id, 'limit' => null];
        }

        $cart->setCheckout([self::ANSWER => $rows]);

        return ['ok' => true, 'message' => __('Gift card :card applied. Balance: :balance.', [
            'card' => $card->masked(),
            'balance' => format_price((int) $card->balance),
        ])];
    }

    public function remove(CartManager $cart, int $cardId): void
    {
        $rows = array_values(array_filter($this->applied($cart), fn ($row) => $row['id'] !== $cardId));

        // setCheckout MERGES, and null erases — an empty list is written as null.
        $cart->setCheckout([self::ANSWER => $rows ?: null]);
    }

    public function limit(CartManager $cart, int $cardId, ?int $limit): void
    {
        $rows = array_map(
            fn ($row) => $row['id'] === $cardId ? ['id' => $cardId, 'limit' => $limit !== null ? max(0, $limit) : null] : $row,
            $this->applied($cart),
        );

        $cart->setCheckout([self::ANSWER => $rows ?: null]);
    }

    /** Why this card cannot be used by whoever is asking, or null. */
    public function refusal(Card $card): ?string
    {
        return match ($card->state()) {
            'pending' => __('This gift card has not been activated yet.'),
            'disabled' => __('This gift card is switched off.'),
            'expired' => __('This gift card expired on :date.', ['date' => $card->expires_at?->format('d.m.Y')]),
            'redeemed' => __('Nothing is left on this gift card.'),
            default => $card->owner_user_id && $card->owner_user_id !== auth()->id()
                ? __('This gift card belongs to another account.')
                : null,
        };
    }

    /** @return Collection<int, Card> applied and usable, soonest to expire first */
    public function cards(CartManager $cart): Collection
    {
        $rows = $this->applied($cart);

        if ($rows === []) {
            return collect();
        }

        return Card::whereIn('id', array_column($rows, 'id'))->get()
            ->filter(fn (Card $card) => $this->refusal($card) === null)
            ->sortBy(fn (Card $card) => [$card->expires_at?->timestamp ?? PHP_INT_MAX, $card->id])
            ->values();
    }

    /**
     * What the applied cards pay of a bill of `$total` — one line per card,
     * each carrying its own "remove" and "no more than" controls.
     *
     * @return list<array<string, mixed>>
     */
    public function quote(CartManager $cart, int $total, int $shipping): array
    {
        $cards = $this->cards($cart);

        if ($cards->isEmpty()) {
            return [];
        }

        $limits = array_column($this->applied($cart), 'limit', 'id');
        $lines = $this->linesOf($cart);
        $left = max(0, $total);
        $out = [];

        foreach ($cards as $card) {
            $take = min((int) $card->balance, $this->rules->payable($card, $lines, $total, $shipping), $left);

            if (($limits[$card->id] ?? null) !== null) {
                $take = min($take, (int) $limits[$card->id]);
            }

            $left -= max(0, $take);

            $out[] = [
                'source' => 'giftcard',
                'card_id' => $card->id,
                'label' => __('Gift card :card', ['card' => $card->masked()]),
                'amount' => max(0, $take),
                'remove_url' => route('giftcards.cart.remove', $card->id),
                'extra_html' => view('torgash.giftcards::storefront.limit', [
                    'card' => $card,
                    'taken' => max(0, $take),
                    'limit' => $limits[$card->id] ?? null,
                ])->render(),
            ];
        }

        return $out;
    }

    /**
     * Spend what quote() promised, for real, inside the order's transaction.
     *
     * @return list<array{label: string, amount: int}>
     */
    public function capture(Order $order, CartManager $cart, int $expected): array
    {
        $shipping = (int) $order->shipping_cost;
        $taken = [];

        foreach ($this->quote($cart, (int) $order->total, $shipping) as $line) {
            if ($line['amount'] <= 0) {
                continue;
            }

            $card = Card::find($line['card_id']);
            $got = $card ? $this->ledger->redeem($card, $line['amount'], $order->id) : 0;

            if ($got > 0) {
                $taken[] = ['label' => $line['label'], 'amount' => $got];
            }
        }

        return $taken;
    }

    /**
     * The basket's lines as Rules reads them, their categories fetched in one
     * query.
     *
     * @return list<array{product_id: int, line_total: int, category_ids: list<int>}>
     */
    public function linesOf(CartManager $cart): array
    {
        $items = $cart->items();
        $categories = DB::table('m_catalog_category_product')
            ->whereIn('product_id', $items->pluck('product.id')->all())
            ->get(['product_id', 'category_id'])
            ->groupBy('product_id');

        return $items->map(fn ($item) => [
            'product_id' => (int) $item->product->id,
            'line_total' => (int) $item->line_total,
            'category_ids' => ($categories->get($item->product->id) ?? collect())->pluck('category_id')->map('intval')->all(),
        ])->values()->all();
    }
}
