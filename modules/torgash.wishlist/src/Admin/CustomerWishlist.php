<?php

namespace Torgash\Wishlist\Admin;

use Torgash\Catalog\Models\Product;
use Torgash\Wishlist\Models\WishItem;
use Torgash\Wishlist\Models\WishList;

/**
 * One customer's wishlist on their card in the admin, through core's
 * `core.admin.customer.panels`.
 *
 * The statistics screen stays "products and numbers, never people" — that is
 * a question about the catalogue. This is the other question, asked on the
 * page that is already about one person: what are they waiting for, and would
 * a price-drop letter even reach them. Behind the module's own permission,
 * the one that already lets somebody read wishlists.
 */
class CustomerWishlist
{
    public const LIMIT = 12;

    public function register(): void
    {
        hook_listen('core.admin.customer.panels', function (array $panels, array $context) {
            $customer = $context['customer'] ?? null;

            if (! $customer || ! auth()->user()?->can('wishlist.stats')) {
                return $panels;
            }

            return [...$panels, ['key' => 'wishlist', 'position' => 30, 'html' => $this->panel($customer)]];
        });

        // The list is the account's own and goes with it (the foreign key
        // cascades); said so before anybody presses the button.
        hook_listen('core.admin.customer.delete_notes', function (array $notes, array $context) {
            $customer = $context['customer'] ?? null;
            $count = $customer ? WishItem::whereIn('list_id', WishList::where('user_id', $customer->id)->select('id'))->count() : 0;

            return $count === 0 ? $notes : [...$notes, ['key' => 'wishlist', 'deleted' => true,
                'label' => trans_choice('The wishlist of :count product is deleted.|The wishlist of :count products is deleted.', $count, ['count' => $count])]];
        });
    }

    protected function panel($customer): string
    {
        $list = WishList::where('user_id', $customer->id)->first();
        $items = $list
            ? $list->items()->latest('id')->limit(self::LIMIT)->get()
            : collect();

        // Deleted products included: "wanted and gone" is still an answer.
        $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id'))
            ->withCardData()->get()->keyBy('id');

        return view('torgash.wishlist::admin.customer', [
            'list' => $list,
            'items' => $items->filter(fn ($item) => $products->has($item->product_id))->values(),
            'products' => $products,
            'count' => $list ? $list->items()->count() : 0,
        ])->render();
    }
}
