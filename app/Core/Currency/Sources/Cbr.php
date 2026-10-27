<?php

namespace App\Core\Currency\Sources;

use App\Core\Currency\RateSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * The Russian central bank's official rates.
 *
 * Taken from `cbr-xml-daily.ru/daily_json.js` rather than the bank's own
 * `XML_daily.asp`: same numbers, published as JSON in UTF-8, and no XML in
 * Windows-1251 to transcode. It is a mirror, which is a dependency worth
 * stating out loud — a shop that will not have one points `JsonFeed` at the
 * bank's own endpoint instead, which is the whole reason JsonFeed exists.
 *
 * `Nominal` is the catch and the reason this is a preset rather than a JsonFeed
 * config: some currencies are quoted per 10, 100 or 10 000 units (the yen, the
 * tenge), so the price of ONE unit is `Value / Nominal`. Reading `Value`
 * straight off would price the yen a hundredfold.
 */
class Cbr implements RateSource
{
    protected const URL = 'https://www.cbr-xml-daily.ru/daily_json.js';

    public static function key(): string
    {
        return 'cbr';
    }

    public static function label(): string
    {
        return 'Central Bank of Russia';
    }

    public function base(): string
    {
        return 'RUB';
    }

    public function quotes(Collection $currencies): array
    {
        $body = Http::timeout(20)->get(self::URL)->throw()->json();

        $quotes = [];

        foreach ((array) ($body['Valute'] ?? []) as $code => $row) {
            $value = (float) ($row['Value'] ?? 0);
            $nominal = (float) ($row['Nominal'] ?? 1);

            if ($value > 0 && $nominal > 0) {
                $quotes[strtoupper((string) $code)] = $value / $nominal;
            }
        }

        return $quotes;
    }
}
