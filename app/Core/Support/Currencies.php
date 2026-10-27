<?php

namespace App\Core\Support;

use App\Models\Currency;
use App\Models\Storefront;

/**
 * The currency table, read once per request.
 *
 * format_price() used to run `where('code', …)->first()` on every call, which
 * on a category page meant twenty-odd identical queries for the same four-row
 * table — the single biggest N+1 in the storefront, and one that grows with the
 * number of products shown.
 *
 * The whole table is loaded instead of caching per code: it is four rows, one
 * query answers every currency the page could ask for, and there is no
 * invalidation question because the memo lives exactly as long as the request
 * that filled it. A registered singleton and not a `static` inside the helper,
 * so it dies with the request even under a long-lived worker.
 */
class Currencies
{
    /** @var array<string, Currency>|null */
    protected ?array $byCode = null;

    protected ?string $books = null;

    public function find(?string $code): ?Currency
    {
        if ($code === null || $code === '') {
            return null;
        }

        return $this->all()[$code] ?? null;
    }

    /** @return array<string, Currency> code => currency */
    public function all(): array
    {
        return $this->byCode ??= Currency::query()->get()->keyBy('code')->all();
    }

    /**
     * The books currency where no storefront is being served — a console
     * command, a queued job: the default storefront's, else the currency at
     * rate 1. Before this the answer there was a bare "USD", so the demo
     * installed into a shop in hryvnias priced a $29 card at 29 ₴ (found on
     * the first clean install, 2026-10-27).
     */
    public function books(): string
    {
        return $this->books ??= (string) (Storefront::query()->where('is_default', true)->value('default_currency')
            ?? collect($this->all())->first(fn (Currency $currency) => (float) $currency->rate === 1.0)?->code
            ?? 'USD');
    }

    /**
     * The smallest amount the books currency SHOWS, in minor units: 1 for a
     * currency printed with cents, 100 for one printed in whole units.
     */
    public function step(): int
    {
        $decimals = (int) ($this->find(shop_currency())?->decimals ?? 2);

        return 10 ** max(0, 2 - min(2, $decimals));
    }

    /**
     * An amount worked out from a percentage, in books minor units, rounded
     * to what the books show. A shop in whole hryvnias showed 51 396 ₴ on the
     * product (10% off, a warranty at 6.6%) and asked 51 395.76 ₴ in the
     * basket, because every percentage kept its kopecks and only the printing
     * dropped them (found on the first clean install, 2026-10-27). Taxes do
     * not come here: they keep their own rounding setting.
     */
    public function round(float $minor): int
    {
        $step = $this->step();

        return (int) (round($minor / $step) * $step);
    }

    /** For the admin, which edits currencies and then reads them back. */
    public function flush(): void
    {
        $this->byCode = null;
        $this->books = null;
    }
}
