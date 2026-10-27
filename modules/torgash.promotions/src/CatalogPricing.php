<?php

namespace Torgash\Promotions;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Torgash\Catalog\Models\Product;
use Torgash\Promotions\Models\Promotion;
use Torgash\Promotions\Models\PromotionTier;

/**
 * Feeds Product::cheapestPriceCandidate() through the two hooks it exposes —
 * see that method's docblock for why there are two.
 */
class CatalogPricing
{
    /**
     * 'replace'-mode promotions, as competing candidates against the base
     * list price — the same rule a wholesale tier or a price type plays by,
     * so "cheapest wins" picks between them with no special case.
     *
     * @return list<array{price: int, label: string}>
     */
    public function candidates(Product $product, ?User $user, int $qty): array
    {
        $candidates = [];

        foreach (app(PromotionCache::class)->activeInZone(Promotion::ZONE_CATALOG) as $promotion) {
            if ($promotion->combine !== Promotion::REPLACE || ! $promotion->appliesIn()) {
                continue;
            }

            $tier = $this->winningTier($promotion, $product, $user);
            if ($tier) {
                $candidates[] = [
                    'price' => max(0, $product->price - $tier->discountFor($product->price)),
                    'label' => $promotion->t('name'),
                ];
            }
        }

        return $candidates;
    }

    /**
     * 'stack_add' and 'stack_multiply' promotions, layered onto whatever the
     * candidate stage already settled on.
     *
     * @param  array{price: int, label: ?string}  $best
     * @return array{price: int, label: ?string}
     */
    public function adjust(array $best, Product $product, ?User $user, int $qty): array
    {
        $matches = [];
        foreach (app(PromotionCache::class)->activeInZone(Promotion::ZONE_CATALOG) as $promotion) {
            if ($promotion->combine === Promotion::REPLACE || ! $promotion->appliesIn()) {
                continue;
            }

            $tier = $this->winningTier($promotion, $product, $user);
            if ($tier) {
                $matches[] = [$promotion, $tier];
            }
        }

        if ($matches === []) {
            return $best;
        }

        $price = $best['price'];
        $labels = array_filter([$best['label']]);

        // Every stack_add tier discounts the SAME base (the price the
        // candidate stage settled on) and the amounts are summed, so "10% +
        // 10%" reads as "20% off", not as two sequential 10% cuts.
        $addTotal = 0;
        foreach ($matches as [$promotion, $tier]) {
            if ($promotion->combine === Promotion::STACK_ADD) {
                $addTotal += $tier->discountFor($price);
                $labels[] = $promotion->t('name');
            }
        }
        $price = max(0, $price - $addTotal);

        // Multiply tiers compound: each is computed off what the PREVIOUS one
        // left, in priority order — the literal "double discount".
        foreach ($matches as [$promotion, $tier]) {
            if ($promotion->combine === Promotion::STACK_MULTIPLY) {
                $price = max(0, $price - $tier->discountFor($price));
                $labels[] = $promotion->t('name');
            }
        }

        return ['price' => $price, 'label' => implode(' + ', array_unique($labels)) ?: $best['label']];
    }

    /**
     * Narrows a product query to what a RUNNING catalog promotion makes
     * cheaper — the catalogue's "on sale" filter, whose other branch is a
     * price below its RRP (see `catalog.filters.discounted`).
     *
     * SQL, not the matching() above: this answers about a paginated grid, and
     * a page filtered in PHP after the query leaves the pager counting
     * products the page does not hold. Which is why it goes through
     * Conditions::applyProductQuery(), the same rule engine the admin's
     * preview uses, rather than a second reading of the same rules.
     *
     * A tier is only included when it would really cut THIS product's price,
     * and that is not a property of the tier alone: "everything down to 999"
     * leaves a 500 item at 500 (see PromotionTier::discountFor()), so the
     * `to_*` kinds carry a comparison against the price column instead of
     * being taken on trust. A tier that changes no price at all — free
     * shipping, a gift, a customer group — is not a discount on a product and
     * is left out.
     *
     * `winningTier()` is deliberately NOT used: it answers "which tier prices
     * this product", first match wins, while the question here is whether ANY
     * tier reaches it.
     */
    public function discounted(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();
        $tiers = [];

        foreach (app(PromotionCache::class)->activeInZone(Promotion::ZONE_CATALOG) as $promotion) {
            if (! $promotion->appliesIn()) {
                continue;
            }

            foreach ($promotion->tiers as $tier) {
                $tiers[] = $tier;
            }
        }

        return $query->where(function (Builder $q) use ($tiers, $user) {
            // Nothing running: said out loud, because an orWhere() closure
            // that adds no clause is dropped by the query builder — which
            // would turn "in a sale" into "every product in the shop".
            $q->whereRaw('1 = 0');

            foreach ($tiers as $tier) {
                if (! $cut = $this->priceCut($tier)) {
                    continue;
                }

                $q->orWhere(function (Builder $sub) use ($tier, $user, $cut) {
                    $cut($sub);

                    Conditions::applyProductQuery($sub, $tier->rules(), $tier->matchAny(), $user);
                });
            }
        });
    }

    /**
     * When this tier actually makes a product cheaper, as a clause on the
     * price column — or null when it never can.
     *
     * @return (callable(Builder): void)|null
     */
    protected function priceCut(PromotionTier $tier): ?callable
    {
        $value = (int) ($tier->discount_value ?? 0);

        return match ($tier->discount_type) {
            PromotionTier::PERCENT, PromotionTier::FIXED => $value > 0
                ? fn (Builder $q) => $q->where('price', '>', 0)
                : null,
            // A target price: a cut only for what costs more than the target.
            PromotionTier::TO_AMOUNT => fn (Builder $q) => $q->where('price', '>', max(0, $value)),
            PromotionTier::TO_PERCENT => $value < 100
                ? fn (Builder $q) => $q->where('price', '>', 0)
                : null,
            default => null,
        };
    }

    /**
     * Which running catalog promotions this product is actually in — for the
     * storefront's own use (the note under the price, the link to the sale's
     * page), never for pricing.
     *
     * Same winningTier() the price goes through, so a product can never be
     * advertised as part of a sale whose tiers do not match it. Cheap enough
     * for a product page (one product against a memoised list); anything
     * asking this for a WHOLE GRID should not — the card already says what a
     * product costs, and a badge per card is what catalog's labels are for.
     *
     * @return list<Promotion>
     */
    public function matching(Product $product, ?User $user = null): array
    {
        $matched = [];

        foreach (app(PromotionCache::class)->activeInZone(Promotion::ZONE_CATALOG) as $promotion) {
            if ($promotion->appliesIn() && $this->winningTier($promotion, $product, $user)) {
                $matched[] = $promotion;
            }
        }

        return $matched;
    }

    /**
     * When the soonest timed sale this product is in stops — for the card's
     * countdown. Sales that show no countdown, or have nothing to count to,
     * are skipped BEFORE the product is matched against them.
     */
    public function countdownFor(Product $product, ?User $user = null): ?CarbonInterface
    {
        $soonest = null;

        foreach (app(PromotionCache::class)->activeInZone(Promotion::ZONE_CATALOG) as $promotion) {
            if (! $promotion->show_countdown || ! $promotion->appliesIn()) {
                continue;
            }

            $until = $promotion->countdownEndsAt();

            if ($until && $until->isFuture() && (! $soonest || $until->lessThan($soonest))
                && $this->winningTier($promotion, $product, $user)) {
                $soonest = $until;
            }
        }

        return $soonest;
    }

    /**
     * `$user` is who is being priced FOR, not who is logged in: both hooks are
     * handed the visitor by Product::cheapestPriceCandidate(), which is what
     * lets a catalog promotion ask about the customer group. It reaches this
     * far and no further — PromotionCache holds the promotion LIST, never a
     * resolved price, so nothing here can be served to the next visitor.
     */
    protected function winningTier(Promotion $promotion, Product $product, ?User $user = null): ?PromotionTier
    {
        foreach ($promotion->tiers as $tier) {
            if (Conditions::matchesProduct($tier->rules(), $tier->matchAny(), $product, $user)) {
                return $tier;
            }
        }

        return null;
    }
}
