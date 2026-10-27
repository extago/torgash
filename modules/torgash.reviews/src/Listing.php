<?php

namespace Torgash\Reviews;

use Illuminate\Database\Eloquent\Builder;
use Torgash\Reviews\Models\Review;

/**
 * Which reviews a product page shows, and in what order (PLAN §32.3).
 *
 * One class because three callers ask: the section drawn inside the product
 * page, the fragment that replaces its list when a reader changes the order,
 * and the API. Three copies of "published reviews of this product, newest
 * first" would be three chances for one of them to forget `reviews()` and
 * start printing the shop's own replies as reviews.
 *
 * The orders are fixed rather than an open registry, which is the opposite of
 * what the catalogue does with `Sorts` — and deliberately. A catalogue sort is
 * a shop preference over a set nobody can enumerate; these four are the whole
 * of what a review can be ranked by, they are the same in every shop, and a
 * module that wanted a fifth would be describing a different feature.
 *
 * **"Most helpful" counts only the YES votes.** Subtracting the noes would let
 * a brigade bury a review by voting it down rather than merely failing to
 * raise it, and the question the reader is answering is "did this help me",
 * not "should this be seen".
 *
 * Every order closes with `id` — the rule `Sorts::apply()` already lives by:
 * Postgres may return tied rows in any order, and a page boundary inside a tie
 * shows one review twice and another never. Reviews tie constantly (every
 * unvoted one has nought helpful votes).
 */
class Listing
{
    public function __construct(protected Group $group)
    {
    }

    /**
     * The published reviews a product's page reads — its own, or its whole
     * variation group's ({@see Group}).
     */
    public function published(int $productId): Builder
    {
        return Review::query()->reviews()->published()->whereIn('product_id', $this->group->ids($productId));
    }

    public const DEFAULT = 'newest';

    /** The most one request draws — "show more" asks again with a larger number, up to this. */
    public const LIMIT = 200;

    /** The periods a reader can narrow to, in days. */
    public const PERIODS = [30, 60, 365];

    /**
     * What the reader asked for, from the address — the ORDER and the
     * FILTERS (stars, period, buyers only, with photos) and how many are shown.
     * Every parameter is `review_*` (the order keeps its old `reviews`): the
     * product page's address belongs to several modules at once. Anything
     * unknown is the default — these are links somebody may have kept.
     *
     * @param  array<string, mixed>  $query
     * @return array{order: string, stars: ?int, period: ?int, buyers: bool, photos: bool, shown: int}
     */
    public static function state(array $query): array
    {
        $stars = (int) ($query['review_stars'] ?? 0);
        $period = (int) ($query['review_period'] ?? 0);
        $perPage = static::perPage();

        return [
            'order' => static::normalise($query['reviews'] ?? null),
            'stars' => $stars >= 1 && $stars <= 5 ? $stars : null,
            'period' => in_array($period, self::PERIODS, true) ? $period : null,
            'buyers' => ! empty($query['review_buyers']),
            'photos' => ! empty($query['review_photos']),
            // Whole pages only, never past the cap: "show more" adds a page.
            'shown' => max($perPage, min(self::LIMIT, (int) ceil(max(1, (int) ($query['review_shown'] ?? 0)) / $perPage) * $perPage)),
        ];
    }

    /** The address parameters that say a state — what a link carries; the defaults are left out. */
    public static function params(array $state): array
    {
        return array_filter([
            'reviews' => $state['order'] !== self::DEFAULT ? $state['order'] : null,
            'review_stars' => $state['stars'],
            'review_period' => $state['period'],
            'review_buyers' => $state['buyers'] ? 1 : null,
            'review_photos' => $state['photos'] ? 1 : null,
            'review_shown' => $state['shown'] > static::perPage() ? $state['shown'] : null,
        ], fn ($value) => $value !== null);
    }

    /** True when a filter (not the order) narrows the list. */
    public static function filtered(array $state): bool
    {
        return $state['stars'] !== null || $state['period'] !== null || $state['buyers'] || $state['photos'];
    }

    public static function perPage(): int
    {
        return max(1, min(50, (int) setting('torgash.reviews', 'per_page', 10)));
    }

    /** @return array<string, string> key => label, for the control */
    public static function orders(): array
    {
        return [
            'newest' => __('Newest first'),
            'helpful' => __('Most helpful'),
            'best' => __('Highest rated'),
            'worst' => __('Lowest rated'),
        ];
    }

    /** A word nobody knows is the default, never an error: this is a link somebody may have kept. */
    public static function normalise(?string $order): string
    {
        return array_key_exists((string) $order, static::orders()) ? (string) $order : self::DEFAULT;
    }

    /**
     * The published reviews of one product, ordered — `reviews()` first, so
     * the shop's own replies are never listed as reviews of their own.
     */
    public function query(int $productId, string $order = self::DEFAULT, array $state = []): Builder
    {
        $query = $this->filter($this->published($productId), $state)
            ->with(['ratings', 'replies', 'media']);

        return $this->order($query, $order);
    }

    /**
     * The filters of a state. Stars bucket as Review::stars() rounds (4.5 is
     * five), so a filter and the spread bars beside it never disagree.
     */
    public function filter(Builder $query, array $state): Builder
    {
        return $query
            ->when($state['stars'] ?? null, fn (Builder $q, int $stars) => $q
                ->where('rating', '>=', $stars === 1 ? 0 : $stars - 0.5)
                ->when($stars < 5, fn (Builder $below) => $below->where('rating', '<', $stars + 0.5)))
            ->when($state['period'] ?? null, fn (Builder $q, int $days) => $q->where('created_at', '>=', now()->subDays($days)))
            ->when($state['buyers'] ?? false, fn (Builder $q) => $q->where('verified', true))
            ->when($state['photos'] ?? false, fn (Builder $q) => $q->whereHas('media', fn (Builder $m) => $m->where('collection_name', Photos::COLLECTION)));
    }

    /** How many reviews the state's filters leave — "shown 10 of 14". */
    public function count(int $productId, array $state = []): int
    {
        return $this->filter($this->published($productId), $state)->count();
    }

    /**
     * The most helpful praise and the most helpful complaint, side by side —
     * only where there are enough reviews for the pair to mean something
     * (setting `highlights_from`, 0 never) and BOTH halves exist with at
     * least one "helpful" vote: "the most helpful of three" is a label on a
     * coin toss. Praise is four stars and up, a complaint two and below.
     *
     * @return array{positive: Review, negative: Review}|null
     */
    public function highlights(int $productId, int $total): ?array
    {
        $from = (int) setting('torgash.reviews', 'highlights_from', 10);

        if ($from <= 0 || $total < $from) {
            return null;
        }

        $best = fn (bool $praise) => $this->published($productId)
            ->where('helpful_yes', '>', 0)
            ->where('rating', $praise ? '>=' : '<', $praise ? 3.5 : 2.5)
            ->with(['ratings', 'replies', 'media'])
            ->orderByDesc('helpful_yes')->orderByDesc('id')
            ->first();

        $positive = $best(true);
        $negative = $positive ? $best(false) : null;

        return $positive && $negative ? ['positive' => $positive, 'negative' => $negative] : null;
    }

    /**
     * The customers' photographs of a product, newest first — the strip over
     * the list. Only from PUBLISHED reviews: a photo is governed by its
     * review's moderation (see Photos).
     *
     * @return \Illuminate\Support\Collection<int, \Spatie\MediaLibrary\MediaCollections\Models\Media>
     */
    public function photos(int $productId, int $limit = 24)
    {
        return \Spatie\MediaLibrary\MediaCollections\Models\Media::query()
            ->where('model_type', (new Review)->getMorphClass())
            ->where('collection_name', Photos::COLLECTION)
            ->whereIn('model_id', $this->published($productId)->select('id'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function order(Builder $query, string $order): Builder
    {
        $query = match (static::normalise($order)) {
            'helpful' => $query->orderByDesc('helpful_yes'),
            'best' => $query->orderByDesc('rating'),
            'worst' => $query->orderBy('rating'),
            default => $query,
        };

        // Newest last in every case, then the id: a tie in helpfulness is
        // broken by recency, and a tie in both by something that cannot tie.
        return $query->orderByDesc('id');
    }

    /** What the page draws: the state's filters, its order, as many as it shows. */
    public function forProduct(int $productId, string $order = self::DEFAULT, ?array $state = null)
    {
        $state ??= static::state(['reviews' => $order]);

        return $this->query($productId, $order, $state)->limit($state['shown'])->get();
    }
}
