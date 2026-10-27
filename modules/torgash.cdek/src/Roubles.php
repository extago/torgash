<?php

namespace Torgash\Cdek;

use App\Core\Support\Currencies;

/**
 * Engine money → roubles, which is what CDEK is told about value: what the
 * goods are worth (insurance), what to collect on delivery.
 *
 * The engine keeps hundredths whatever a currency's `decimals` say — those are
 * how it is DISPLAYED (format_price divides by 100 too); dividing by the
 * display decimals once declared a $5 000 cart as 40 million roubles. Rates
 * are "units of that currency per one base unit", so another currency goes
 * through the base: ÷ its rate, × the rouble's.
 */
final class Roubles
{
    /** Roubles for $minor hundredths of $currency, or null when there is no way to say. */
    public static function fromMinor(int $minor, ?string $currency = null): ?float
    {
        $currency = $currency ?: shop_currency();
        $major = $minor / 100;

        if ($currency === 'RUB') {
            return round($major, 2);
        }

        $currencies = app(Currencies::class);
        $from = $currency === shop_currency() ? 1.0 : (float) ($currencies->find($currency)?->rate ?? 0);
        $rub = (float) ($currencies->find('RUB')?->rate ?? 0);

        if ($from <= 0 || $rub <= 0) {
            return null;
        }

        return round($major / $from * $rub, 2);
    }
}
