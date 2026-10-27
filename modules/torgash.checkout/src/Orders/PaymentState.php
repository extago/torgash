<?php

namespace Torgash\Checkout\Orders;

/**
 * The fixed vocabulary of "where is the money" — deliberately NOT a table.
 *
 * Order statuses are a table because every shop invents its own workflow. These
 * are the opposite: the engine has to reason about them (what is owed, what can
 * be refunded, what a trigger means by "paid"), and a gateway module has to map
 * its acquirer's words onto them. A vocabulary anyone can rename is a vocabulary
 * nothing can rely on.
 *
 * Six of the seven come straight from what a card acquirer actually reports
 * (created / hold / success / failure / expired / reversed in monobank's case);
 * PARTIALLY_REFUNDED is ours, because a partial refund is the state an admin
 * screen has to be able to show and a full-refund flag cannot.
 */
class PaymentState
{
    /** Nothing has arrived — the default for a new order, and for cash on delivery. */
    public const PENDING = 'pending';

    /** The money is reserved on the customer's card but not taken (a two-phase "hold"). */
    public const AUTHORIZED = 'authorized';

    public const PAID = 'paid';

    /**
     * Some of the money is here and the rest is not yet: a gift card covered
     * part of the order and the remainder waits for a gateway, a courier or a
     * transfer (PLAN §40.4). Ours, like PARTIALLY_REFUNDED — no acquirer says
     * it, because no acquirer sees the gift card.
     */
    public const PARTIALLY_PAID = 'partially_paid';

    public const PARTIALLY_REFUNDED = 'partially_refunded';

    public const REFUNDED = 'refunded';

    /** The gateway said no: a declined card, a failed transfer. */
    public const FAILED = 'failed';

    /** Nobody ever paid and the invoice ran out of time. */
    public const EXPIRED = 'expired';

    /** @return list<string> in the order they make sense on a screen */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::PARTIALLY_PAID,
            self::AUTHORIZED,
            self::PAID,
            self::PARTIALLY_REFUNDED,
            self::REFUNDED,
            self::FAILED,
            self::EXPIRED,
        ];
    }

    /**
     * States in which the shop is holding the customer's money, so the amount
     * on the order is a real number rather than a leftover.
     *
     * AUTHORIZED counts: the funds are ring-fenced and the shop can take them,
     * which is exactly why a hold has to be visible as something other than
     * "unpaid" on the screen where a manager decides whether to ship.
     *
     * @return list<string>
     */
    public static function withMoney(): array
    {
        return [self::PARTIALLY_PAID, self::AUTHORIZED, self::PAID, self::PARTIALLY_REFUNDED];
    }

    public static function holdsMoney(?string $state): bool
    {
        return in_array($state, self::withMoney(), true);
    }

    /** English source string; the RU translation lives in lang/ru.json. */
    public static function label(?string $state): string
    {
        return __(self::labelKey($state));
    }

    /** The label's English source, for a line translated when it is read (OrderLog). */
    public static function labelKey(?string $state): string
    {
        return match ($state) {
            self::PENDING => 'Awaiting payment',
            self::AUTHORIZED => 'Authorized',
            self::PAID => 'Paid',
            self::PARTIALLY_PAID => 'Partially paid',
            self::PARTIALLY_REFUNDED => 'Partially refunded',
            self::REFUNDED => 'Refunded',
            self::FAILED => 'Payment failed',
            self::EXPIRED => 'Payment expired',
            default => (string) $state,
        };
    }

    /** daisyUI badge modifier, so the list reads at a glance. */
    public static function badge(?string $state): string
    {
        return match ($state) {
            self::PAID => 'badge-success',
            self::AUTHORIZED, self::PARTIALLY_PAID => 'badge-info',
            self::PARTIALLY_REFUNDED, self::REFUNDED => 'badge-warning',
            self::FAILED, self::EXPIRED => 'badge-error',
            default => 'badge-ghost',
        };
    }
}
