<?php

namespace App\Core\Currency;

use App\Core\Support\Currencies;
use App\Models\Currency;

/**
 * Turning a source's quotes into the shop's own rates: the crossing, the
 * markup, the guard, and the write.
 *
 * All of it lives here rather than in each source, because each part is a place
 * to get it wrong once instead of once per source.
 *
 * **The crossing.** A source quotes in ITS currency — hryvnia for the NBU,
 * roubles for the CBR, usually dollars for a crypto feed — and our column means
 * something else entirely: how many units of that currency one unit of the
 * SHOP's currency buys. So
 *
 *     rate(X) = price_of_books_in_source_base / price_of_X_in_source_base
 *
 * Books over X, not X over books. Confuse the two and every price on the
 * storefront is multiplied where it should be divided — the exact mistake
 * CLAUDE.md warns about, now automated and therefore worth writing down.
 * The shop's own currency falls out of the formula as 1, which is the invariant
 * everything else depends on.
 *
 * **The guard.** Since a rate change now rewrites the books price of every
 * product priced in that currency (§52), a broken feed is no longer a cosmetic
 * problem: one bad number reprices the catalogue. So a rate that moves further
 * than the shop allows in one go is refused and reported, not applied. Central
 * bank rates move by fractions of a percent a day; 20% is a feed that has
 * started answering in a different unit.
 */
class RateUpdater
{
    /**
     * Ask a chain of sources (RateSources::chain), each only for what the ones
     * before it did not answer.
     *
     * A source that throws — its API changed, its mirror is gone — is named in
     * `failed` and the next one is asked; one that cannot quote the books'
     * currency answers nothing and hands everything on. So a feed breaking
     * costs a shop nothing but a line in the report, while the report still
     * says it broke: `answered` names who actually gave each rate.
     *
     * @param  list<RateSource>  $sources
     * @return array{updated: int, skipped: int, missing: list<string>, refused: list<string>, base: string, answered: array<string, string>, failed: array<string, string>}
     */
    public function updateFrom(array $sources): array
    {
        $total = ['updated' => 0, 'skipped' => 0, 'missing' => [], 'refused' => [], 'base' => shop_currency(), 'answered' => [], 'failed' => []];
        $wanted = null;

        foreach ($sources as $source) {
            try {
                $report = $this->update($source, $wanted);
            } catch (\Throwable $e) {
                $total['failed'][$source::key()] = mb_substr($e->getMessage(), 0, 200);

                continue;
            }

            $total['updated'] += $report['updated'];
            $total['skipped'] = max($total['skipped'], $report['skipped']);
            $total['refused'] = [...$total['refused'], ...$report['refused']];

            if ($report['updated'] > 0) {
                $total['answered'][$source::key()] = (string) $report['updated'];
            }

            // A source that could not cross at all — no books currency in its
            // answer — found nothing, and leaves the list as it was.
            if (in_array($report['base'], $report['missing'], true)) {
                continue;
            }

            // What is still to be found.
            $wanted = $report['missing'];

            if ($wanted === []) {
                break;
            }
        }

        $total['missing'] = $wanted ?? $this->wanted();

        return $total;
    }

    /** Every code an automatic update is supposed to reach — nobody answered yet. */
    protected function wanted(): array
    {
        $books = strtoupper(shop_currency());

        return Currency::query()->where('auto_rate', true)->pluck('code')
            ->map(fn ($code) => strtoupper($code))
            ->reject(fn ($code) => $code === $books)
            ->values()->all();
    }

    /**
     * Fetch and apply. Returns a report: what moved, what was left alone, why.
     *
     * @param  list<string>|null  $only  the codes still to be found; null = all
     * @return array{updated: int, skipped: int, missing: list<string>, refused: list<string>, base: string}
     */
    public function update(RateSource $source, ?array $only = null): array
    {
        $books = shop_currency();
        $markup = (float) setting('core', 'rates_markup', 0);
        $maxChange = (float) setting('core', 'rates_max_change', 0);

        $currencies = Currency::query()->get();
        $quotes = array_change_key_case($source->quotes($currencies), CASE_UPPER);

        // The source's own base is worth one of itself, whether or not it said so.
        $quotes[strtoupper($source->base())] ??= 1.0;

        $report = ['updated' => 0, 'skipped' => 0, 'missing' => [], 'refused' => [], 'base' => $books];

        $booksQuote = $quotes[strtoupper($books)] ?? null;

        if (! $booksQuote) {
            // Without the shop's own currency in the answer there is nothing to
            // cross against, and every rate would be wrong in the same
            // direction. Refuse the whole run rather than write any of it.
            $report['missing'][] = $books;

            return $report;
        }

        foreach ($currencies as $currency) {
            $code = strtoupper($currency->code);

            if ($code === strtoupper($books)) {
                // One unit of the books' currency is one unit of it. Never
                // fetched, never marked up: that 1 is what every other rate,
                // and every stored amount in the shop, is expressed against.
                continue;
            }

            // An earlier source in the chain already answered for it.
            if ($only !== null && ! in_array($code, $only, true)) {
                continue;
            }

            if (! $currency->auto_rate) {
                $report['skipped']++;

                continue;
            }

            if (empty($quotes[$code])) {
                $report['missing'][] = $code;

                continue;
            }

            $rate = ($booksQuote / $quotes[$code]) * (1 + $markup / 100);

            if (! $this->withinReason($currency, $rate, $maxChange)) {
                $report['refused'][] = $code;

                continue;
            }

            // Through the model, so `core.currency.rate_changed` fires and the
            // prices derived from this rate are recomputed — see
            // CoreServiceProvider::announceCurrencyChanges().
            $currency->update([
                // As the source gave it: a rate has significant digits, not decimal places.
                'rate' => $rate,
                'rate_updated_at' => now(),
            ]);

            $report['updated']++;
        }

        app(Currencies::class)->flush();

        return $report;
    }

    /**
     * A rate that has moved further than the shop allows is a feed that has
     * changed its mind about units, not a market that has moved. Zero turns the
     * guard off; a currency with no usable rate yet has nothing to compare
     * against and is always allowed.
     */
    protected function withinReason(Currency $currency, float $rate, float $maxChange): bool
    {
        $was = (float) $currency->rate;

        if ($maxChange <= 0 || $was <= 0) {
            return true;
        }

        return abs($rate - $was) / $was * 100 <= $maxChange;
    }
}
