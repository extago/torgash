<?php

namespace Torgash\Giftcards;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Models\Order;
use Torgash\Giftcards\Models\Card;
use Torgash\Giftcards\Models\GiftProduct;

/**
 * Where a card comes from — three doors, one way of making it:
 *
 * - by hand, one card for one person (the admin's "Issue");
 * - a batch for printing, N cards of X, not handed over yet (`pending`) until
 *   a manager activates the one they are selling at the till;
 * - an order that bought one, and only once it is PAID: an unpaid order that
 *   produced a working code would be a code for nothing (PLAN §40.4).
 */
class Issuer
{
    public function __construct(protected Ledger $ledger) {}

    /**
     * @param  array<string, mixed>  $attributes  any card column (recipient, message, rules, …)
     * @return array{0: Card, 1: string} the card and its code, which is only ever in plain here
     */
    public function issue(int $amount, array $attributes = []): array
    {
        return DB::transaction(function () use ($amount, $attributes) {
            [$code, $hash] = $this->freshCode();

            $card = Card::create($attributes + [
                'code_hash' => $hash,
                'code_encrypted' => Crypt::encryptString($code),
                'last4' => substr($code, -4),
                'initial_amount' => $amount,
                'public_token' => Card::newToken(),
                'expires_at' => array_key_exists('expires_at', $attributes) ? $attributes['expires_at'] : self::defaultExpiry(),
                'activated_at' => ($attributes['status'] ?? Card::ACTIVE) === Card::ACTIVE ? now() : null,
                'locale' => $attributes['locale'] ?? app()->getLocale(),
                'storefront_id' => $attributes['storefront_id'] ?? current_storefront()?->id,
            ]);

            $this->ledger->issue($card, $amount);

            return [$card->refresh(), $code];
        });
    }

    /**
     * N cards of one amount, not handed over yet unless `active` is asked for.
     *
     * @return list<Card>
     */
    public function batch(int $count, int $amount, string $label, array $attributes = []): array
    {
        $cards = [];

        for ($i = 0; $i < $count; $i++) {
            $cards[] = $this->issue($amount, $attributes + [
                'source' => 'batch',
                'batch' => $label,
                'status' => Card::PENDING,
            ])[0];
        }

        return $cards;
    }

    /**
     * The cards an order bought, issued once. Asked every time the order
     * changes; issues nothing until it is paid, and nothing twice — each card
     * is keyed by the order, the line and its number within the line.
     *
     * @return list<Card> the ones issued NOW
     */
    public function fromOrder(Order $order): array
    {
        if ($order->payment_state !== \Torgash\Checkout\Orders\PaymentState::PAID) {
            return [];
        }

        $lines = array_values(array_filter(
            (array) $order->items,
            fn ($line) => is_array($line) && ! empty($line['product_id']) && empty($line['parent_key']),
        ));
        $giftIds = GiftProduct::whereIn('product_id', array_column($lines, 'product_id'))->pluck('product_id')->all();
        $issued = [];

        foreach ($lines as $line) {
            if (! in_array((int) $line['product_id'], array_map('intval', $giftIds), true)) {
                continue;
            }

            $answers = (array) (((array) ($line['config'] ?? []))[GiftConfig::KEY] ?? []);
            $amount = (int) ($line['unit_price'] ?? 0);
            $key = (string) ($line['key'] ?? $line['product_id']);

            for ($seq = 1; $seq <= max(1, (int) ($line['qty'] ?? 1)); $seq++) {
                if ($amount <= 0 || Card::where('order_id', $order->id)->where('order_line_key', $key)->where('order_seq', $seq)->exists()) {
                    continue;
                }

                $issued[] = $this->issue($amount, [
                    'source' => 'sold',
                    'order_id' => $order->id,
                    'order_line_key' => $key,
                    'order_seq' => $seq,
                    // Counted from the order, not from the day it was paid:
                    // the owner's rule — the buyer saw the date when ordering.
                    'expires_at' => self::defaultExpiry($order->created_at),
                    'recipient_name' => $answers['to_name'] ?? null,
                    'recipient_email' => $answers['to_email'] ?? null,
                    'sender_name' => $answers['from_name'] ?? $order->customer_name,
                    'message' => $answers['message'] ?? null,
                    'deliver_at' => isset($answers['deliver_on']) ? \Illuminate\Support\Carbon::parse($answers['deliver_on'])->startOfDay()->addHours(9) : null,
                    // Bought for oneself: it is the buyer's the moment it exists.
                    'owner_user_id' => empty($answers['to_email']) ? $order->user_id : null,
                    'locale' => $order->locale,
                    'storefront_id' => $order->storefront_id,
                ])[0];
            }
        }

        return $issued;
    }

    /** The shop's default life of a card from `$from`, or null for "no end". */
    public static function defaultExpiry(?CarbonInterface $from = null): ?CarbonInterface
    {
        $months = (int) setting('torgash.giftcards', 'expiry_months', 12);

        return $months > 0 ? ($from ?? now())->copy()->addMonthsNoOverflow($months)->endOfDay() : null;
    }

    /** @return array{0: string, 1: string} */
    protected function freshCode(): array
    {
        do {
            $code = Codes::generate();
            $hash = Codes::hash($code);
        } while (Card::where('code_hash', $hash)->exists());

        return [$code, $hash];
    }
}
