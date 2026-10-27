<?php

namespace Torgash\Wishlist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Torgash\Catalog\Models\Product;
use Torgash\Wishlist\Models\WishList;

/**
 * "Cheaper than when you saved it" (PLAN §35.3), run nightly and after an
 * exchange rate moves (a product priced in another currency moves in the
 * books with it).
 *
 * Measured against `price_at_add` with the price for NOBODY in particular
 * (`publicPrice()`), never one customer's group price. Only lists whose owner
 * switched it on AND has an account — a letter needs an address, and a guest
 * has none —, only for a product that can be bought right now (a cheaper
 * product nobody can buy is not news; its drop is still there to tell when it
 * returns), only past the shop's threshold, and ONE letter per list. Once
 * told, `price_at_add` becomes the new price: one drop, one letter. A price
 * that ROSE moves nothing — back down to where it was saved is not news to
 * somebody who saved it there.
 */
class CheckPriceDrops implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public function handle(): void
    {
        $percent = max(0, (float) setting('torgash.wishlist', 'price_drop_percent'));
        $items = fn () => DB::table('m_wishlist_items as i')
            ->join('m_wishlist_lists as l', 'l.id', '=', 'i.list_id')
            ->where('l.notify_price', true)->whereNotNull('l.user_id')->whereNotNull('i.price_at_add');

        $items()->distinct()->orderBy('i.product_id')->select('i.product_id')
            ->chunk(200, function ($chunk) use ($items, $percent) {
                $prices = [];

                foreach (Product::reachable()->whereIn('id', $chunk->pluck('product_id'))->get() as $product) {
                    if ($product->isOrderable()) {
                        $prices[$product->id] = $product->publicPrice();
                    }
                }

                $drops = [];

                foreach ($items()->whereIn('i.product_id', array_keys($prices))->get(['i.id', 'i.list_id', 'i.product_id', 'i.price_at_add']) as $item) {
                    $old = (int) $item->price_at_add;
                    $new = $prices[(int) $item->product_id];

                    if ($new < $old && ($old - $new) * 100 >= $percent * $old) {
                        $drops[(int) $item->list_id][] = ['item' => (int) $item->id, 'product_id' => (int) $item->product_id, 'old' => $old, 'new' => $new];
                    }
                }

                $this->tell($drops);
            });
    }

    /** @param  array<int, list<array{item: int, product_id: int, old: int, new: int}>>  $drops */
    protected function tell(array $drops): void
    {
        foreach (WishList::with('user', 'storefront')->whereIn('id', array_keys($drops))->get() as $list) {
            if (! $list->user?->email) {
                continue;
            }

            $lines = $drops[$list->id];
            // In the list's own language (the locale it remembers), never the worker's.
            \App\Core\Mail\Letters::send(\Torgash\Wishlist\Mail\WishlistLetters::CHEAPER, $list->user->email,
                ['list' => $list, 'drops' => array_map(fn ($d) => array_diff_key($d, ['item' => 1]), $lines)], $list->locale ?: null);

            foreach ($lines as $line) {
                DB::table('m_wishlist_items')->where('id', $line['item'])->update(['price_at_add' => $line['new']]);
            }
        }
    }
}
