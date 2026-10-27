<?php

namespace Torgash\Promotions;

use App\Models\User;
use Torgash\Promotions\Models\Promotion;
use Torgash\Promotions\Models\PromotionTier;

/**
 * Cart-zone promotions as `checkout.extra_totals` / `checkout.order.extra_totals`
 * lines — a negative, non-`included` amount, exactly like any other line on
 * that hook, just subtracting instead of adding. Kept independent of
 * torgash.checkout's Coupon on purpose: a coupon's
 * own discount is a separate column and this contributes nothing to it.
 *
 * Takes plain arrays rather than a Basket so the same code prices a live cart
 * and a placed order being recalculated without either caller adapting its
 * shape — same idiom as torgash.taxes' TaxCalculator::forCart().
 */
class CartPricing
{
    /**
     * `$couponDiscount` is what torgash.checkout's own coupon has already taken
     * off the goods — the COUPON GOES FIRST and promotions discount what is
     * left of the cart, never the sticker subtotal. Two reasons: "50% off" that
     * cuts 50.00 from a cart the customer only owes 60.00 for is not 50% off,
     * and the two discounts are otherwise computed from the same base by two
     * modules that cannot see each other, which is how a cart ends up
     * over-discounted. Zero in a shop with no coupon, which is the common case
     * and leaves every existing price exactly where it was.
     *
     * @param  list<array{product_id: int, category_ids: list<int>, qty: int, line_total: int}>  $lines
     * @return list<array{code: string, label: string, amount: int, included: bool}>
     */
    public function lines(array $lines, int $subtotal, int $shippingCost, ?User $user, ?string $customerRef = null, ?string $paymentMethodId = null, int $couponDiscount = 0, array $where = []): array
    {
        $quantity = array_sum(array_column($lines, 'qty'));
        $categoryIds = $this->categoryIds($lines);

        $matches = [];
        foreach ($this->winners($lines, $subtotal, $quantity, $categoryIds, $user, $customerRef, $paymentMethodId, $where) as [$promotion, $tier]) {
            // A side-effect tier (give_group/free_product) is still the
            // winning tier of its promotion — first-match-wins is unaffected —
            // it just contributes no price line here. See isSideEffect().
            if (! $tier->isSideEffect()) {
                $matches[] = [$promotion, $tier];
            }
        }

        if ($matches === []) {
            return [];
        }

        $result = [];

        // The two pools every cut is drawn from, and the reason an order can no
        // longer come out negative: goods discounts spend the first, a
        // free_shipping tier spends the second, and neither can be overdrawn.
        $goodsLeft = max(0, $subtotal - $couponDiscount);
        $shippingLeft = max(0, $shippingCost);

        // 'replace': the single highest-priority match wins (activeInZone is
        // already priority-ordered); every other replace-mode match is
        // skipped entirely rather than merely losing a tie.
        foreach ($matches as [$promotion, $tier]) {
            if ($promotion->combine === Promotion::REPLACE) {
                $base = $goodsLeft;
                $this->emit($result, $promotion, $tier, $this->spend($tier, $lines, $base, $goodsLeft, $shippingLeft));
                break;
            }
        }

        // 'stack_add': every match discounts the SAME base (what remains
        // after the replace stage), summed rather than compounded — "10% +
        // 10%" reads as 20% off, not two sequential cuts. The base stays
        // fixed; what shrinks is the pool, so the sum still cannot exceed the
        // cart.
        $addBase = $goodsLeft;
        foreach ($matches as [$promotion, $tier]) {
            if ($promotion->combine === Promotion::STACK_ADD) {
                $this->emit($result, $promotion, $tier, $this->spend($tier, $lines, $addBase, $goodsLeft, $shippingLeft));
            }
        }

        // 'stack_multiply': each is computed off what the PREVIOUS one left —
        // the real "double discount", compounding rather than summing.
        foreach ($matches as [$promotion, $tier]) {
            if ($promotion->combine === Promotion::STACK_MULTIPLY) {
                $base = $goodsLeft;
                $this->emit($result, $promotion, $tier, $this->spend($tier, $lines, $base, $goodsLeft, $shippingLeft));
            }
        }

        return $result;
    }

    /**
     * A cut of zero buys nothing and is not a line: it would print "−0.00" on
     * the bill, and — because the snapshot charges `used_count` for every line
     * it writes — it would spend a redemption of a limited promotion that gave
     * the customer nothing.
     *
     * @param  list<array{code: string, label: string, amount: int, included: bool}>  $result
     */
    protected function emit(array &$result, Promotion $promotion, PromotionTier $tier, int $cut): void
    {
        if ($cut > 0) {
            $result[] = $this->line($promotion, $tier, $cut);
        }
    }

    /**
     * give_group/free_product tiers that won their promotion — read ONLY at
     * real order placement (checkout.order_placed / checkout.order.items),
     * never while a live cart is merely being re-priced: a group upgrade or a
     * free gift must happen exactly once, and `lines()` above may run many
     * times over the same cart before the customer ever places it.
     *
     * @param  list<array{product_id: int, category_ids: list<int>, qty: int, line_total: int}>  $lines
     * @return list<array{promotion: Promotion, tier: PromotionTier}>
     */
    public function sideEffects(array $lines, int $subtotal, ?User $user, ?string $customerRef = null, ?string $paymentMethodId = null, array $where = []): array
    {
        $quantity = array_sum(array_column($lines, 'qty'));
        $categoryIds = $this->categoryIds($lines);

        $result = [];
        foreach ($this->winners($lines, $subtotal, $quantity, $categoryIds, $user, $customerRef, $paymentMethodId, $where) as [$promotion, $tier]) {
            if ($tier->isSideEffect()) {
                $result[] = ['promotion' => $promotion, 'tier' => $tier];
            }
        }

        return $result;
    }

    /**
     * Every promotion's winning tier, usage-gated, whatever kind it is —
     * lines() and sideEffects() each keep only the half they care about.
     *
     * @param  list<array{product_id: int, category_ids: list<int>, qty: int, line_total: int}>  $lines
     * @param  list<int>  $categoryIds
     * @return list<array{0: Promotion, 1: PromotionTier}>
     */
    protected function winners(
        array $lines,
        int $subtotal,
        int $quantity,
        array $categoryIds,
        ?User $user,
        ?string $customerRef,
        ?string $paymentMethodId,
        array $where = [],
    ): array {
        $winners = [];
        foreach (app(PromotionCache::class)->activeInZone(Promotion::ZONE_CART) as $promotion) {
            // Where it applies (storefront, language, who is looking) before
            // what it needs (subtotal, items): a sale that is not on this
            // storefront has nothing to say about this cart at all. `$where`
            // carries the answers a caller knows better than the request does —
            // an order keeps the storefront it was placed on.
            if (! $promotion->appliesIn($where) || ! $promotion->usableBy($user, $customerRef)) {
                continue;
            }

            $tier = $this->winningTier($promotion, $subtotal, $quantity, $lines, $categoryIds, $user, $paymentMethodId);
            if ($tier) {
                $winners[] = [$promotion, $tier];
            }
        }

        return $winners;
    }

    /**
     * How much this tier cuts, in minor units — off $base, off shipping for a
     * free_shipping tier, or off the matching lines' own total when the tier
     * carries a `discount_scope` ("discount on products / categories"):
     * a scoped tier computes off its own matching total rather than off the
     * running base, the same amount whatever else on the cart has already been
     * discounted.
     *
     * Whatever it computes, it SPENDS from the pool it draws on and can never
     * overdraw it. `discountFor()` has always capped a tier against its own
     * base; nothing capped the sum, so two stacking 60% promotions placed an
     * order with a negative total — a shop that owes the buyer money — and two
     * free_shipping promotions refunded one delivery twice. The pool is that
     * cap, and it is here rather than at the end because a promotion that got
     * nothing must also not print a line or spend a redemption.
     *
     * @param  list<array{product_id: int, category_ids: list<int>, line_total: int}>  $lines
     * @param  int  $goodsLeft  spent by every discount but free shipping
     * @param  int  $shippingLeft  spent only by free shipping
     */
    protected function spend(PromotionTier $tier, array $lines, int $base, int &$goodsLeft, int &$shippingLeft): int
    {
        if ($tier->discount_type === PromotionTier::FREE_SHIPPING) {
            $cut = $shippingLeft;
            $shippingLeft -= $cut;

            return $cut;
        }

        if ($scope = $tier->scope()) {
            $base = $this->scopedTotal($lines, $scope);
        }

        $cut = min($tier->discountFor(max(0, $base)), $goodsLeft);
        $goodsLeft -= $cut;

        return $cut;
    }

    /** @param  list<array{product_id: int, category_ids: list<int>, line_total: int}>  $lines */
    protected function scopedTotal(array $lines, array $scope): int
    {
        $type = $scope['type'] ?? 'product';
        $ids = array_map('intval', (array) ($scope['ids'] ?? []));

        return array_sum(array_map(
            fn (array $line) => $this->lineInScope($line, $type, $ids) ? (int) ($line['line_total'] ?? 0) : 0,
            $lines,
        ));
    }

    /** @param  array{product_id: int, category_ids: list<int>}  $line @param  list<int>  $ids */
    protected function lineInScope(array $line, string $type, array $ids): bool
    {
        return $type === 'category'
            ? (bool) array_intersect($line['category_ids'] ?? [], $ids)
            : in_array($line['product_id'] ?? null, $ids, true);
    }

    /**
     * `target` and `scope` say what the cut was drawn from, which is what a
     * placed order needs to lay it over its lines (see `shares()`); a caller
     * printing the line reads the first four keys and nothing else.
     *
     * @return array{code: string, label: string, amount: int, included: bool, target: string, scope: array|null}
     */
    protected function line(Promotion $promotion, PromotionTier $tier, int $cut): array
    {
        return [
            'code' => 'promotion:'.$promotion->id,
            'label' => $promotion->t('name'),
            'amount' => -$cut,
            'included' => false,
            'target' => $tier->discount_type === PromotionTier::FREE_SHIPPING ? 'shipping' : 'goods',
            'scope' => $tier->scope(),
        ];
    }

    /**
     * A goods cut laid over the lines it was taken from, in proportion to
     * their totals, to the minor unit (largest remainder, so the shares add
     * up to the cut exactly). A scoped tier is laid only over the lines in
     * its scope — those are the ones it discounted.
     *
     * @param  list<array{key: string, product_id: int, category_ids: list<int>, line_total: int}>  $lines
     * @param  array{type?: string, ids?: list<int>}|null  $scope
     * @return array<string, int> line key => amount
     */
    public function shares(int $cut, array $lines, ?array $scope): array
    {
        $weights = [];
        foreach ($lines as $line) {
            if ($scope !== null && ! $this->lineInScope($line, $scope['type'] ?? 'product', array_map('intval', (array) ($scope['ids'] ?? [])))) {
                continue;
            }
            if ((int) $line['line_total'] > 0) {
                $weights[(string) $line['key']] = ($weights[(string) $line['key']] ?? 0) + (int) $line['line_total'];
            }
        }

        $sum = array_sum($weights);
        if ($cut <= 0 || $sum <= 0) {
            return [];
        }

        $shares = [];
        $remainders = [];
        foreach ($weights as $key => $weight) {
            $shares[$key] = intdiv($cut * $weight, $sum);
            $remainders[$key] = ($cut * $weight) % $sum;
        }

        arsort($remainders);
        foreach (array_keys($remainders) as $key) {
            if (array_sum($shares) >= $cut) {
                break;
            }
            $shares[$key]++;
        }

        return $shares;
    }

    /** @param  list<array{product_id: int, category_ids: list<int>, qty: int, line_total: int}>  $lines */
    protected function winningTier(
        Promotion $promotion,
        int $subtotal,
        int $quantity,
        array $lines,
        array $categoryIds,
        ?User $user,
        ?string $paymentMethodId = null,
    ): ?PromotionTier {
        foreach ($promotion->tiers as $tier) {
            if (Conditions::matchesCart($tier->rules(), $tier->matchAny(), $subtotal, $quantity, $lines, $categoryIds, $user, $paymentMethodId)) {
                return $tier;
            }
        }

        return null;
    }

    /** @param  list<array{category_ids: list<int>}>  $lines @return list<int> */
    protected function categoryIds(array $lines): array
    {
        return array_values(array_unique(array_merge([], ...array_column($lines, 'category_ids'))));
    }
}
