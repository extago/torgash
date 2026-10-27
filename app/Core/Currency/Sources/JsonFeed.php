<?php

namespace App\Core\Currency\Sources;

use App\Core\Currency\RateSource;
use App\Models\Currency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Any JSON endpoint at all, described in four settings instead of in code.
 *
 * This is the answer to the thing that always happens: a shop needs a rate from
 * a source nobody wrote a class for. Presets cover the two central banks most
 * of our shops use; this covers the rest — another bank, an aggregator, a
 * crypto exchange, or the shop's own script publishing whatever it likes.
 *
 * Four questions, and no more:
 *
 *   URL       where to ask. Containing `{code}` it is asked once per currency,
 *             which is what an endpoint like
 *             `…/simple/price?ids={code}&vs_currencies=usd` needs; without it,
 *             once for everything.
 *   Base      the currency the feed's numbers are in.
 *   Path      where in the answer the number is, as a dotted path with `{code}`
 *             in it: `rates.{code}`, `{code}.usd`, `data.{code}.price`.
 *   Inverted  tick when the feed says "how many X per one base" (the ECB's
 *             shape) rather than "what one X costs in base" (a bank's).
 *
 * `{code}` is the currency's ISO code, or its "key in the feed" where the shop
 * has set one — which is how `BTC` becomes `bitcoin` without this class knowing
 * anything about crypto.
 */
class JsonFeed implements RateSource
{
    public static function key(): string
    {
        return 'json';
    }

    public static function label(): string
    {
        return 'A JSON endpoint of your own';
    }

    public function base(): string
    {
        return strtoupper((string) setting('core', 'rates_json_base', '')) ?: shop_currency();
    }

    public function quotes(Collection $currencies): array
    {
        $url = trim((string) setting('core', 'rates_json_url', ''));
        $path = trim((string) setting('core', 'rates_json_path', '')) ?: '{code}';
        $invert = (bool) setting('core', 'rates_json_invert', false);

        if ($url === '') {
            return [];
        }

        // One request for everything, unless the URL itself is per-currency.
        $perCurrency = str_contains($url, '{code}');
        $shared = $perCurrency ? null : $this->fetch($url);

        $quotes = [];

        foreach ($currencies as $currency) {
            $key = $this->keyFor($currency);
            $body = $perCurrency ? $this->fetch(str_replace('{code}', $key, $url)) : $shared;

            if ($body === null) {
                continue;
            }

            $value = (float) data_get($body, str_replace('{code}', $key, $path), 0);

            if ($value <= 0) {
                continue;
            }

            $quotes[strtoupper($currency->code)] = $invert ? 1 / $value : $value;
        }

        return $quotes;
    }

    /** What this currency is called in the feed. */
    protected function keyFor(Currency $currency): string
    {
        return trim((string) $currency->rate_key) ?: $currency->code;
    }

    /** @return array<mixed>|null */
    protected function fetch(string $url): ?array
    {
        $response = Http::timeout(20)->get($url);

        // A single currency's endpoint being down must not fail the whole run:
        // the updater leaves an unanswered rate exactly as it was and says so.
        return $response->successful() ? (array) $response->json() : null;
    }
}
