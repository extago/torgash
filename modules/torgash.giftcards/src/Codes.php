<?php

namespace Torgash\Giftcards;

/**
 * What a gift card's code looks like, and how it is found again.
 *
 * Sixteen characters from an alphabet with no 0/O and no 1/I/L — the coupon
 * generator's own — because a printed card is read by a person and typed by a
 * thumb. Shown in groups of four; read in ANY spelling, since the customer
 * copies it from a letter with the dashes, types it in lower case or pastes it
 * with a space at the end.
 *
 * Found by an HMAC under the application key, never by the code itself: a
 * database dump alone is then not a list of spendable money.
 */
class Codes
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 16;

    public static function generate(): string
    {
        $alphabet = self::ALPHABET;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /** Capitals, no dashes or spaces — the one spelling that is looked up. */
    public static function normalise(string $typed): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($typed)));
    }

    /**
     * Whether a typed string could be a card at all. A coupon's code never is
     * sixteen characters of this alphabet by accident often enough to matter,
     * and when it is, the coupon is looked up first.
     */
    public static function looksLikeCard(string $normalised): bool
    {
        return strlen($normalised) === self::LENGTH
            && strspn($normalised, self::ALPHABET) === self::LENGTH;
    }

    public static function hash(string $normalised): string
    {
        return hash_hmac('sha256', 'giftcard:'.$normalised, (string) config('app.key'));
    }

    /** XXXX-XXXX-XXXX-XXXX */
    public static function format(string $normalised): string
    {
        return implode('-', str_split($normalised, 4));
    }
}
