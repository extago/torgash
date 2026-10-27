<?php

namespace Torgash\Reviews;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Torgash\Reviews\Models\Criterion;
use Torgash\Reviews\Models\Review;

/**
 * The number a card prints, kept in one row per product.
 *
 * Computed on publication rather than on reading: a listing of twelve products
 * would otherwise be twelve GROUP BYs over a table that only grows, and the
 * same average would be worked out again for the card, the page heading, the
 * structured data and the sort. One row, written when something is published,
 * unpublished or deleted.
 *
 * A row for a product with no reviews is DELETED rather than left at zero:
 * "nobody has said anything" and "everybody gave it nothing" are different
 * answers, and a sort by rating must not put a brand-new product below a
 * one-star one.
 *
 * Priming ({@see prime()}) is the rule every per-product lookup in this engine
 * follows — a grid announces its set, and a module answers it in one query.
 */
class Aggregates
{
    /** @var array<int, object|null> */
    protected array $memo = [];

    public function __construct(protected Group $group)
    {
    }

    /**
     * Recount one product from its published reviews — and, where variations
     * share their reviews ({@see Group}), every member of its group with it:
     * they read the same reviews, so they carry the same row, and a card or a
     * sort reads it without knowing groups exist.
     */
    public function recalculate(int $productId): void
    {
        $ids = $this->group->ids($productId);
        $reviews = Review::reviews()->published()->whereIn('product_id', $ids)->get(['id', 'rating', 'recommend']);

        foreach ($ids as $id) {
            unset($this->memo[$id]);
        }

        if ($reviews->isEmpty()) {
            DB::table('m_reviews_aggregates')->whereIn('product_id', $ids)->delete();

            return;
        }

        $rated = $reviews->filter(fn (Review $r) => $r->rating !== null);
        $spread = [];

        foreach (range(1, 5) as $star) {
            $spread[(string) $star] = $rated->filter(fn (Review $r) => $r->stars() === $star)->count();
        }

        $row = [
            'rating' => $rated->isEmpty() ? 0 : round($rated->avg('rating'), 1),
            'reviews_count' => $reviews->count(),
            'spread' => json_encode($spread),
            'criteria' => json_encode($this->criterionAverages($reviews->pluck('id')->all())),
            'recommend_yes' => $reviews->where('recommend', 'yes')->count(),
            'recommend_neutral' => $reviews->where('recommend', 'neutral')->count(),
            'recommend_no' => $reviews->where('recommend', 'no')->count(),
            'updated_at' => now(),
            'created_at' => now(),
        ];

        DB::table('m_reviews_aggregates')->upsert(
            array_map(fn (int $id) => ['product_id' => $id] + $row, $ids),
            ['product_id'],
            array_keys(array_diff_key($row, ['created_at' => true])),
        );
    }

    /**
     * Recount every product that has a mark or a review — after the reading
     * itself changed (`share_variations` switched), when no single review
     * moved, and the admin's "Recount". A group is counted once, through its
     * first member.
     *
     * @return int how many products were recounted
     */
    public function recalculateAll(): int
    {
        $ids = DB::table('m_reviews_reviews')->whereNull('parent_id')->distinct()->pluck('product_id')
            ->merge(DB::table('m_reviews_aggregates')->pluck('product_id'))
            ->map('intval')->unique()->sort()->values();

        $this->group->flush();
        $done = [];

        foreach ($ids as $id) {
            if (isset($done[$id])) {
                continue;
            }

            $this->recalculate($id);

            foreach ($this->group->ids($id) as $member) {
                $done[$member] = true;
            }
        }

        return count($done);
    }

    /**
     * Averages per criterion, keyed by CODE rather than id — the code is what
     * survives a criterion being deleted and recreated, and it is what a
     * template reads.
     *
     * @param  list<int>  $reviewIds
     * @return array<string, float>
     */
    protected function criterionAverages(array $reviewIds): array
    {
        if ($reviewIds === []) {
            return [];
        }

        $rows = DB::table('m_reviews_ratings')
            ->whereIn('review_id', $reviewIds)
            ->groupBy('criterion_id')
            ->get([DB::raw('criterion_id'), DB::raw('avg(value) as average')]);

        if ($rows->isEmpty()) {
            return [];
        }

        $codes = Criterion::whereIn('id', $rows->pluck('criterion_id'))->pluck('code', 'id');
        $out = [];

        foreach ($rows as $row) {
            if ($code = $codes[$row->criterion_id] ?? null) {
                $out[$code] = round((float) $row->average, 1);
            }
        }

        return $out;
    }

    /** One query for a whole page of products — the `withCardData()` rule, for another module's data. */
    public function prime(Collection $products): void
    {
        $ids = $products->pluck('id')->map('intval')
            ->reject(fn (int $id) => array_key_exists($id, $this->memo))
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $rows = DB::table('m_reviews_aggregates')->whereIn('product_id', $ids)->get()->keyBy('product_id');

        foreach ($ids as $id) {
            $this->memo[$id] = $rows[$id] ?? null;
        }
    }

    public function for(int $productId): ?object
    {
        if (! array_key_exists($productId, $this->memo)) {
            $this->memo[$productId] = DB::table('m_reviews_aggregates')->where('product_id', $productId)->first();
        }

        return $this->memo[$productId];
    }

    /** @return array<int, int> star => how many, always five keys */
    public function spread(int $productId): array
    {
        $stored = json_decode((string) ($this->for($productId)->spread ?? ''), true);
        $out = [];

        foreach (range(1, 5) as $star) {
            $out[$star] = (int) ($stored[(string) $star] ?? 0);
        }

        return $out;
    }

    /** @return array<string, float> criterion code => average */
    public function criteria(int $productId): array
    {
        return (array) json_decode((string) ($this->for($productId)->criteria ?? ''), true);
    }

    public function flush(): void
    {
        $this->memo = [];
    }
}
