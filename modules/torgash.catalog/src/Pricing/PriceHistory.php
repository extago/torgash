<?php

namespace Torgash\Catalog\Pricing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;

/**
 * What a product has cost, and the lowest of it before the current price
 * (PLAN §36, the Omnibus rule) — the one place that writes the history and
 * the one place that derives `prior_lowest_price` from it.
 *
 * Only the PUBLIC price (`publicPrice()`, for nobody in particular): a
 * customer group's price is a private arrangement, not an announced
 * reduction. Written when a product is saved with something its price is made
 * of, and by an hourly sweep — a scheduled promotion changes the price
 * without anything being written, and only the sweep can see it. The same
 * price twice writes nothing.
 */
class PriceHistory
{
    /** The window the rule speaks of; a setting, 30 by the directive. */
    public function days(): int
    {
        return max(1, (int) setting('torgash.catalog', 'lowest_price_days'));
    }

    /** @return bool whether the price had moved (and was written) */
    public function record(Product $product, ?Carbon $at = null): bool
    {
        $at ??= now();
        $price = $product->publicPrice();

        $moved = DB::transaction(function () use ($product, $price, $at) {
            $open = DB::table('m_catalog_price_history')->where('product_id', $product->id)->whereNull('ends_at')->lockForUpdate()->first();

            if ($open && (int) $open->price === $price) {
                return false;
            }

            if ($open) {
                DB::table('m_catalog_price_history')->where('id', $open->id)->update(['ends_at' => $at]);
            }

            DB::table('m_catalog_price_history')->insert(['product_id' => $product->id, 'price' => $price, 'starts_at' => $at]);

            return true;
        });

        if ($moved) {
            $this->refresh($product->id);
        }

        return $moved;
    }

    /**
     * The lowest price among what the product cost in the window BEFORE its
     * current price began — NULL when nothing was recorded before it (a new
     * product, or history that has only just started): claiming a reference
     * the shop cannot show is the one wrong answer.
     */
    public function priorLowest(int $productId): ?int
    {
        $since = DB::table('m_catalog_price_history')->where('product_id', $productId)->whereNull('ends_at')->value('starts_at');

        if ($since === null) {
            return null;
        }

        $since = Carbon::parse($since);
        $lowest = DB::table('m_catalog_price_history')
            ->where('product_id', $productId)
            ->whereNotNull('ends_at')
            ->where('starts_at', '<', $since)
            ->where('ends_at', '>', $since->copy()->subDays($this->days()))
            ->min('price');

        return $lowest === null ? null : (int) $lowest;
    }

    /**
     * Written with a plain query: the column is derived, and deriving it must
     * not move `updated_at`, fire the audit or announce a product change.
     */
    public function refresh(int $productId): void
    {
        DB::table('m_catalog_products')->where('id', $productId)->update(['prior_lowest_price' => $this->priorLowest($productId)]);
    }

    /**
     * Everything, once — the hourly sweep. One timestamp for the whole pass,
     * so a promotion that switched on for the whole catalogue starts at one
     * moment for every product rather than drifting across the pass.
     *
     * @return int how many prices had moved
     */
    public function sweep(): int
    {
        $at = now();
        $moved = 0;

        Product::query()->withCardData()->orderBy('id')->chunkById(200, function ($products) use ($at, &$moved) {
            foreach ($products as $product) {
                $moved += $this->record($product, $at) ? 1 : 0;
            }
        });

        return $moved;
    }

    /**
     * What no window can reach any more — measured per product from when ITS
     * current price began, never from today: a price that has held for three
     * months is still measured against the month before it, and those rows
     * are exactly the ones a "delete what is older than 30 days" would take.
     */
    public function prune(): int
    {
        return DB::delete(
            "delete from m_catalog_price_history h
             using m_catalog_price_history o
             where o.product_id = h.product_id and o.ends_at is null
               and h.ends_at is not null
               and h.ends_at < o.starts_at - make_interval(days => ?)",
            [$this->days()],
        );
    }
}
