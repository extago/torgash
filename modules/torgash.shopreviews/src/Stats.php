<?php

namespace Torgash\ShopReviews;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Torgash\ShopReviews\Models\Criterion;
use Torgash\ShopReviews\Models\ShopReview;

/**
 * The shop's numbers: the average, how many, how many would recommend it and
 * buy again, the 1–5 spread and each criterion's average — over PUBLISHED
 * reviews of this storefront (or of all of them).
 *
 * Kept in the cache, because the rating block can sit in the footer of every
 * page; every change that can move a number (`forget()`: a review published,
 * rejected, deleted, a published one written) starts a new generation rather
 * than hunting down one key per storefront.
 */
class Stats
{
    protected const VERSION = 'shopreviews.stats.version';

    /**
     * @return array{count: int, rating: float, recommend: ?int, will_return: ?int,
     *               spread: array<int, int>, criteria: list<array{criterion: Criterion, rating: float, count: int}>}
     */
    public function summary(): array
    {
        $version = (int) Cache::get(self::VERSION, 0);
        $key = 'shopreviews.stats.'.$version.'.'.(current_storefront()?->id ?? 0);

        $numbers = Cache::rememberForever($key, fn () => $this->compute());

        // The criteria are rows with names in every language; the cache keeps
        // only their ids and numbers, and the names are read now.
        $criteria = Criterion::whereIn('id', array_keys($numbers['criteria']))->orderBy('sort')->orderBy('id')->get();

        return [
            ...$numbers,
            'criteria' => $criteria->map(fn (Criterion $criterion) => [
                'criterion' => $criterion,
                'rating' => $numbers['criteria'][$criterion->id]['rating'],
                'count' => $numbers['criteria'][$criterion->id]['count'],
            ])->values()->all(),
        ];
    }

    public static function forget(): void
    {
        Cache::forever(self::VERSION, (int) Cache::get(self::VERSION, 0) + 1);
    }

    /** @return array<string, mixed> */
    protected function compute(): array
    {
        $shown = ShopReview::query()->shown();

        $row = (clone $shown)->selectRaw('count(*) as n, avg(rating) as avg,
                count(recommend) as rec_asked, count(*) filter (where recommend) as rec_yes,
                count(will_return) as ret_asked, count(*) filter (where will_return) as ret_yes')
            ->toBase()->first();

        $spread = (clone $shown)->selectRaw('round(rating)::int as stars, count(*) as n')
            ->groupByRaw('round(rating)::int')->pluck('n', 'stars')->map(fn ($n) => (int) $n)->all();

        $criteria = DB::table('m_shopreviews_ratings as r')
            ->joinSub((clone $shown)->select('id'), 'shown', 'shown.id', '=', 'r.review_id')
            ->selectRaw('r.criterion_id, avg(r.value) as avg, count(*) as n')
            ->groupBy('r.criterion_id')->get()
            ->mapWithKeys(fn ($c) => [(int) $c->criterion_id => ['rating' => round((float) $c->avg, 1), 'count' => (int) $c->n]])
            ->all();

        $share = fn ($yes, $asked) => (int) $asked > 0 ? (int) round(100 * (int) $yes / (int) $asked) : null;

        return [
            'count' => (int) $row->n,
            'rating' => round((float) $row->avg, 1),
            'recommend' => $share($row->rec_yes, $row->rec_asked),
            'will_return' => $share($row->ret_yes, $row->ret_asked),
            'spread' => array_replace([5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0], array_intersect_key($spread, array_flip([1, 2, 3, 4, 5]))),
            'criteria' => $criteria,
        ];
    }
}
