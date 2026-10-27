<?php

namespace Torgash\Shipping;

use App\Core\Support\Currencies;

/**
 * Amounts that are not in the shop's base currency.
 *
 * Everything downstream — the order total, the taxes snapshot, format_price —
 * speaks base-currency minor units, and none of it should learn otherwise. So a
 * rate priced in hryvnia (or a carrier answering in them) is converted here,
 * once, at the moment it becomes a price; nothing else in the engine ever sees
 * the other currency.
 *
 * The rate table's `rate` is "units of this currency per one base unit" — the
 * same number format_price multiplies by to display — so the way back divides
 * by it. Decimals are the currency's own: the shop that shows whole hryvnia
 * stores whole hryvnia on its rates too, and a rate in dollars keeps its cents.
 */
class Money
{
    public static function decimals(?string $code): int
    {
        $currency = $code ? app(Currencies::class)->find($code) : null;

        return $currency ? max(0, (int) $currency->decimals) : 2;
    }

    /** The symbol to put next to a field, or the code when the currency is gone. */
    public static function symbol(?string $code): string
    {
        return $code ? (app(Currencies::class)->find($code)?->symbol ?? $code) : '';
    }

    /** What a human typed → minor units of that currency. */
    public static function toMinor(mixed $value, ?string $code): int
    {
        $number = (float) str_replace(',', '.', (string) $value);

        return (int) round($number * 10 ** self::decimals($code));
    }

    /** Minor units of that currency → what a human reads, without trailing zeros. */
    public static function toMajor(int $minor, ?string $code): string
    {
        $decimals = self::decimals($code);
        $value = number_format($minor / 10 ** $decimals, $decimals, '.', '');

        return $decimals > 0 ? (rtrim(rtrim($value, '0'), '.') ?: '0') : $value;
    }

    /**
     * Minor units of that currency → minor units of the base one, which is what
     * every total in the engine is made of. A null code is already base.
     */
    public static function toBase(int $minor, ?string $code): int
    {
        if ($code === null || $code === '') {
            return $minor;
        }

        $currency = app(Currencies::class)->find($code);

        // A currency that was deleted after a rate was written in it: treating
        // the number as base is the only reading that does not invent a rate,
        // and the amount is at least the right order of magnitude.
        if (! $currency || (float) $currency->rate <= 0) {
            return $minor;
        }

        $major = $minor / 10 ** self::decimals($code);

        return (int) round($major / (float) $currency->rate * 100);
    }
}
