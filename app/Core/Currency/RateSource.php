<?php

namespace App\Core\Currency;

use Illuminate\Support\Collection;

/**
 * Where exchange rates come from.
 *
 * Deliberately narrow: a source answers "what is one unit of each of these
 * currencies worth, in MY base currency" and nothing else. Everything that
 * follows — crossing those quotes into the shop's own currency, the markup, the
 * sanity guard, writing them down, announcing the change so product prices are
 * recomputed — is RateUpdater's, once, for every source.
 *
 * That split is the whole point. A shop switching to a new source (the one
 * thing that always happens, and the reason presets alone are not enough)
 * should be one small class or, better, no class at all: `Sources\JsonFeed`
 * reads any JSON endpoint from settings, which covers a bank nobody has heard
 * of and a crypto exchange equally.
 *
 * The direction is stated once, here, because getting it backwards is the
 * classic bug in this area and it multiplies prices where it should divide:
 * a quote is **how many units of `base()` one unit of that currency costs**.
 * NBU says 41.2 for USD with base UAH: one dollar costs 41.2 hryvnia.
 */
interface RateSource
{
    /** Stable key, stored in the settings. */
    public static function key(): string;

    /** English source label for the settings picker. */
    public static function label(): string;

    /**
     * The currency this source quotes in. NBU answers in hryvnia, the Russian
     * central bank in roubles, a crypto feed usually in dollars — none of them
     * in the shop's own, which is exactly why RateUpdater has to cross them.
     */
    public function base(): string;

    /**
     * Price of one unit of each currency, in `base()`.
     *
     * Given the currencies the shop actually has, so a source can ask for
     * exactly those (and read each one's `rate_key`, which is what a feed keyed
     * by `bitcoin` rather than `BTC` needs). A code the source cannot answer
     * for is simply absent from the result — the updater reports it and leaves
     * that rate alone rather than guessing.
     *
     * @param  Collection<int, \App\Models\Currency>  $currencies
     * @return array<string, float> ISO code => price of one unit, in base()
     */
    public function quotes(Collection $currencies): array;
}
