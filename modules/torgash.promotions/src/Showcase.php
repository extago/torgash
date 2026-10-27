<?php

namespace Torgash\Promotions;

use Illuminate\Support\Collection;
use Torgash\Catalog\Selections\Selection;
use Torgash\Promotions\Models\Promotion;
use Torgash\Promotions\Models\PromotionTier;

/**
 * The sales as the storefront's blocks show them — "the deal with a
 * countdown" and "the current promotions" (see Blocks). Every answer about
 * WHICH sale and WHEN it ends comes from the model (status(),
 * countdownEndsAt()), the same as the sale pages: a block must never count to
 * a different minute than the page it links to.
 */
class Showcase
{
    /**
     * The sale a "deal" block shows, with its products: the one picked, or —
     * none picked — whichever running catalogue sale CLOSES SOONEST, so a
     * shop with daily windows gets a different deal as each one opens and
     * closes, and the timer is always the most urgent one there is.
     *
     * NULL when there is nothing honest to show: not running, no countdown
     * (an open-ended sale has nothing to count), not on this storefront, or
     * not a single product of it is in view.
     *
     * @return array{promotion: Promotion, until: \Illuminate\Support\Carbon, products: Collection}|null
     */
    public function deal(string $pick, int $limit): ?array
    {
        $candidates = Promotion::where('zone', Promotion::ZONE_CATALOG)
            ->where('enabled', true)
            ->when($pick !== '', fn ($query) => $query->whereKey((int) $pick))
            ->with('tiers')
            ->get()
            ->filter(fn (Promotion $promotion) => $promotion->isRunning() && $promotion->appliesIn())
            ->map(fn (Promotion $promotion) => ['promotion' => $promotion, 'until' => $promotion->countdownEndsAt()])
            ->filter(fn (array $row) => $row['until'] !== null)
            ->sortBy(fn (array $row) => $row['until']->getTimestamp())
            ->values();

        foreach ($candidates as $row) {
            $products = app(Selection::class)->products([
                'source' => 'promotion:'.$row['promotion']->id,
                'limit' => $limit,
                'sort' => '',
            ]);

            if ($products->isNotEmpty()) {
                return $row + ['products' => $products];
            }
        }

        return null;
    }

    /**
     * The sales with a page, for the "current promotions" cards: running
     * first, then the ones about to open, never what is over — the order of
     * how much a visitor can act on them, as on the sale list itself.
     *
     * @return Collection<int, Promotion>
     */
    public function current(int $limit, bool $recurring = true): Collection
    {
        return Promotion::published()
            ->where('enabled', true)
            ->with('tiers', 'media')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (Promotion $promotion) => $promotion->appliesIn() && $promotion->status() !== 'ended')
            // A sale on a schedule (every day, weekends) left out by the block
            // that asked — its page and the list of all sales still have it.
            ->filter(fn (Promotion $promotion) => $recurring || $promotion->scheduleWindows()->isEmpty())
            ->sortBy(fn (Promotion $promotion) => match ($promotion->status()) {
                'running' => 0, 'paused' => 1, default => 2,
            })
            ->take($limit)
            ->values();
    }

    /**
     * The offer in two or three characters — "−15%" — for a card that has no
     * picture of its own. Only what reads honestly at a glance: a percentage
     * off, or free delivery. A fixed amount or a set price depends on the
     * product and is left to the sale page.
     */
    public function headline(Promotion $promotion): ?string
    {
        $tier = $promotion->tiers->first();

        return match ($tier?->discount_type) {
            PromotionTier::PERCENT => $tier->discount_value > 0 ? '−'.$tier->discount_value.'%' : null,
            PromotionTier::TO_PERCENT => $tier->discount_value < 100 ? '−'.(100 - (int) $tier->discount_value).'%' : null,
            PromotionTier::FREE_SHIPPING => __('Free delivery'),
            default => null,
        };
    }

    /** The first line of the description, for a card or a block that wants a teaser. */
    public function teaser(Promotion $promotion): string
    {
        return trim(html_entity_decode(strip_tags((string) $promotion->t('description'))));
    }
}
