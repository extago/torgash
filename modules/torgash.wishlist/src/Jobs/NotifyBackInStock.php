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
 * "Back in stock" to everybody who saved one of these products and switched
 * the letter on (PLAN §35.3). QUEUED, and handed every product one action
 * restocked (Restocks): one import can bring back five hundred products, and
 * somebody who saved three of them gets ONE letter naming three.
 *
 * Asked again here whether each can still be bought: a queue runs behind the
 * shop, and a letter sending people to a disabled button is worse than none.
 * A subscription, not a promise to one person — the list keeps it for the
 * next return too, unlike an enquiry, which is answered and done.
 */
class NotifyBackInStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @param  list<int>  $productIds */
    public function __construct(public array $productIds) {}

    public function handle(): void
    {
        $buyable = Product::reachable()->whereIn('id', $this->productIds)->get()
            ->filter(fn (Product $product) => $product->isOrderable())->pluck('id')->all();

        if ($buyable === []) {
            return;
        }

        $byList = [];

        foreach (DB::table('m_wishlist_items as i')
            ->join('m_wishlist_lists as l', 'l.id', '=', 'i.list_id')
            ->where('l.notify_stock', true)->whereNotNull('l.user_id')
            ->whereIn('i.product_id', $buyable)
            ->get(['i.list_id', 'i.product_id']) as $row) {
            $byList[(int) $row->list_id][] = (int) $row->product_id;
        }

        foreach (WishList::with('user', 'storefront')->whereIn('id', array_keys($byList))->get() as $list) {
            if ($list->user?->email) {
                \App\Core\Mail\Letters::send(\Torgash\Wishlist\Mail\WishlistLetters::BACK, $list->user->email,
                    ['list' => $list, 'product_ids' => $byList[$list->id]], $list->locale ?: null);
            }
        }
    }
}
