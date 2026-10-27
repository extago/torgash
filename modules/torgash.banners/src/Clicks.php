<?php

namespace Torgash\Banners;

use Illuminate\Support\Facades\DB;
use Torgash\Banners\Models\Banner;

/**
 * Clicks on banners (PLAN §51, step 5). The page's script tells
 * `banners.click` with `navigator.sendBeacon` as a link inside a banner is
 * followed — so the link stays the plain address it was (no redirect through
 * the shop, nothing for "open in a new tab" to break) — and the count for
 * the banner and the day goes up by one, in one statement.
 */
class Clicks
{
    public function count(int $bannerId): void
    {
        DB::statement(
            'insert into m_banners_clicks (banner_id, day, clicks) values (?, ?, 1)
             on conflict (banner_id, day) do update set clicks = m_banners_clicks.clicks + 1',
            [$bannerId, shop_now()->toDateString()],
        );
    }

    /**
     * Clicks over the last 7 and 30 days and in all, for each banner asked.
     *
     * @param  list<int>  $bannerIds
     * @return array<int, array{week: int, month: int, total: int}>
     */
    public function summary(array $bannerIds): array
    {
        if ($bannerIds === []) {
            return [];
        }

        $today = shop_now();

        return DB::table('m_banners_clicks')
            ->whereIn('banner_id', $bannerIds)
            ->groupBy('banner_id')
            ->selectRaw('banner_id,
                coalesce(sum(clicks) filter (where day > ?), 0) as week,
                coalesce(sum(clicks) filter (where day > ?), 0) as month,
                sum(clicks) as total', [$today->copy()->subDays(7)->toDateString(), $today->copy()->subDays(30)->toDateString()])
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->banner_id => ['week' => (int) $row->week, 'month' => (int) $row->month, 'total' => (int) $row->total]])
            ->all();
    }

    /** Only a banner that exists and is on the page right now is counted. */
    public function countable(int $bannerId): bool
    {
        return Banner::query()->running()->whereKey($bannerId)->exists();
    }
}
