<?php

namespace App\Core\Currency\Sources;

use App\Core\Currency\RateSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * The National Bank of Ukraine's official rates.
 *
 * Free, no key, no registration, one request:
 * `bank.gov.ua/NBUStatService/v1/statdirectory/exchange?json` answers a flat
 * list of `{"cc":"USD","rate":41.2,"exchangedate":"04.09.2026"}`. `rate` is
 * hryvnia for ONE unit, which is this contract's direction already.
 *
 * The official rate is not the rate a bank will actually give a shop — that is
 * what the markup setting is for, and why it exists at all.
 */
class Nbu implements RateSource
{
    protected const URL = 'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?json';

    public static function key(): string
    {
        return 'nbu';
    }

    public static function label(): string
    {
        return 'National Bank of Ukraine';
    }

    public function base(): string
    {
        return 'UAH';
    }

    public function quotes(Collection $currencies): array
    {
        $rows = Http::timeout(20)->get(self::URL)->throw()->json();

        $quotes = [];

        foreach ((array) $rows as $row) {
            $code = strtoupper((string) ($row['cc'] ?? ''));
            $rate = (float) ($row['rate'] ?? 0);

            if ($code !== '' && $rate > 0) {
                $quotes[$code] = $rate;
            }
        }

        return $quotes;
    }
}
