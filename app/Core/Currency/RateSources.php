<?php

namespace App\Core\Currency;

use App\Core\Currency\Sources\Cbr;
use App\Core\Currency\Sources\Ecb;
use App\Core\Currency\Sources\JsonFeed;
use App\Core\Currency\Sources\Nbu;

/**
 * Who can answer "what is a rate today". Three central banks, one configurable
 * endpoint, plus whatever a module adds through `core.currency.rate_sources` —
 * the same idiom as `payments.gateways` and `automations.actions`.
 *
 * A module is the answer for a source with a secret (a paid aggregator's key)
 * or an awkward shape (a signed request, a paginated feed). Everything simpler
 * than that should be JsonFeed and no code at all.
 *
 * **Neutral by default.** Nothing here is ranked by country. What makes a
 * source the right one for a shop is the currency it QUOTES in: the central
 * bank of the shop's own currency is the one whose number the shop's bank and
 * tax office use. So `auto` means exactly that — whichever registered source
 * quotes in the books' currency — and the European Central Bank behind it,
 * the one reference that is nobody's national bank. The picker orders the
 * same way: the sources quoting in the shop's currency first.
 *
 * **A source stops answering** — an API changes, a mirror goes away. That is
 * the one thing certain to happen, so a chosen source is followed by the same
 * fallbacks (`rates_fallback`): each asked only for what the ones before it did
 * not answer, and every step named in the run's report.
 */
class RateSources
{
    /** "The central bank of the shop's own currency, then the ECB." */
    public const AUTO = 'auto';

    /** @return array<string, class-string<RateSource>> */
    public function all(): array
    {
        return hook_filter('core.currency.rate_sources', [
            Nbu::key() => Nbu::class,
            Cbr::key() => Cbr::class,
            Ecb::key() => Ecb::class,
            JsonFeed::key() => JsonFeed::class,
        ]);
    }

    public function find(?string $key): ?RateSource
    {
        $class = $this->all()[$key] ?? null;

        return $class ? app($class) : null;
    }

    /** The one the shop chose, or null when it updates rates by hand. */
    public function current(): ?RateSource
    {
        return $this->chain()[0] ?? null;
    }

    /**
     * The sources to ask, in order: the chosen one, then — unless the shop
     * turned it off — the central bank of its currency and the ECB. Empty when
     * rates are kept by hand. A key nobody answers for any more (a module that
     * registered it is gone) falls through to the fallbacks rather than
     * leaving the shop on stale rates in silence.
     *
     * @return list<RateSource>
     */
    public function chain(): array
    {
        $chosen = (string) setting('core', 'rates_source', '');

        if ($chosen === '') {
            return [];
        }

        $keys = $chosen === self::AUTO ? [] : [$chosen];

        if ($chosen === self::AUTO || setting('core', 'rates_fallback', true)) {
            $keys = [...$keys, ...$this->fallbackKeys()];
        }

        $sources = [];

        foreach (array_unique($keys) as $key) {
            if ($source = $this->find($key)) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * What `auto` resolves to: every source quoting in the books' currency
     * (the configurable endpoint excepted — it quotes in whatever it was told,
     * which is not a statement about the shop), then the ECB.
     *
     * @return list<string>
     */
    public function fallbackKeys(): array
    {
        $books = strtoupper(shop_currency());
        $keys = [];

        foreach ($this->all() as $key => $class) {
            if ($key === JsonFeed::key()) {
                continue;
            }

            $source = $this->find($key);

            if ($source && strtoupper($source->base()) === $books) {
                $keys[] = $key;
            }
        }

        $keys[] = Ecb::key();

        return array_values(array_unique($keys));
    }

    /**
     * For the settings picker: `auto` first, then the sources quoting in the
     * shop's own currency, then the rest, then the configurable endpoint —
     * each saying which currency it quotes in, since that is what makes it
     * right or wrong for this shop.
     *
     * @return array<string, string> key => translated label
     */
    public function options(): array
    {
        $books = strtoupper(shop_currency());
        $own = $others = [];

        foreach ($this->all() as $key => $class) {
            if ($key === JsonFeed::key()) {
                continue;
            }

            $base = strtoupper((string) $this->find($key)?->base());
            $label = __(':source — rates in :currency', ['source' => __($class::label()), 'currency' => $base]);

            $base === $books ? $own[$key] = $label : $others[$key] = $label;
        }

        return [self::AUTO => __('Automatically — the central bank of :currency, then the European Central Bank', ['currency' => $books])]
            + $own
            + $others
            + [JsonFeed::key() => __(JsonFeed::label())];
    }
}
