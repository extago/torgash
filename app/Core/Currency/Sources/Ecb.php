<?php

namespace App\Core\Currency\Sources;

use App\Core\Currency\RateSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * The European Central Bank's reference rates — the one source here that is
 * nobody's national bank.
 *
 * `eurofxref-daily.xml`: free, no key, the same shape since 1999, about thirty
 * currencies against the euro, published once a working day around 16:00 CET.
 * It is what the other sources fall back to (RateSources::chain), because it
 * answers for most of the world's shops without knowing where they are. What it
 * does NOT carry is as important: no hryvnia, no rouble, no tenge — a shop
 * keeping its books in one of those gets nothing crossable from here, and the
 * updater says so rather than guessing.
 *
 * The file says "one euro buys `rate` of X" — the INVERSE of this contract's
 * direction, so the price of one X is `1 / rate` euro.
 */
class Ecb implements RateSource
{
    protected const URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    public static function key(): string
    {
        return 'ecb';
    }

    public static function label(): string
    {
        return 'European Central Bank';
    }

    public function base(): string
    {
        return 'EUR';
    }

    public function quotes(Collection $currencies): array
    {
        $xml = Http::timeout(20)->get(self::URL)->throw()->body();

        $quotes = [];

        // Read by attribute, not through the XML's namespaces: the numbers sit
        // in `<Cube currency="USD" rate="1.0876"/>` whatever wraps them, and a
        // regular expression over that one shape survives an envelope change
        // that a namespaced XPath would not.
        preg_match_all('/currency=[\'"]([A-Z]{3})[\'"]\s+rate=[\'"]([0-9.]+)[\'"]/', $xml, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $code, $rate]) {
            $rate = (float) $rate;

            if ($rate > 0) {
                $quotes[$code] = 1 / $rate;
            }
        }

        return $quotes;
    }
}
