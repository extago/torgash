<?php

namespace App\Core\Currency;

use App\Models\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Are the rates still being updated?" — asked by the guard's nightly
 * statement and by the admin's bell, and answered here once so the two cannot
 * disagree.
 *
 * The failure it exists for is the quiet one: a bank changes its API, every
 * run fails or falls back to nothing, and each currency keeps last month's
 * rate — which looks exactly like a rate that is up to date. So the question is
 * not "did the last run fail" but "when did each rate last MOVE", measured
 * against how often the shop asked for it, with a margin for a central bank's
 * weekend and a public holiday.
 */
class RateFreshness
{
    /** Only while the shop has asked for rates to update themselves. */
    public function watched(): bool
    {
        return app(RateSources::class)->chain() !== [];
    }

    /** How old a rate may be before it counts as stale, by the schedule it is fetched on. */
    public function limit(): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        return match ((string) setting('core', 'rates_frequency', 'daily')) {
            'hourly' => $now->subDay(),
            'six_hours' => $now->subDays(2),
            'weekly' => $now->subDays(10),
            // A central bank publishes on working days: a Friday rate is
            // legitimately the newest on Monday morning, and a holiday adds one.
            default => $now->subDays(4),
        };
    }

    /**
     * The currencies an automatic update is supposed to reach whose rate has
     * not moved within the limit — or never has.
     *
     * @return Collection<int, Currency>
     */
    public function stale(?int $limit = null): Collection
    {
        if (! $this->watched()) {
            return collect();
        }

        return Currency::query()
            ->where('enabled', true)
            ->where('auto_rate', true)
            ->where('code', '!=', shop_currency())
            ->where(fn ($q) => $q->whereNull('rate_updated_at')->orWhere('rate_updated_at', '<', $this->limit()))
            ->orderBy('code')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get();
    }
}
