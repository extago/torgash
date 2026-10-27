<?php

namespace Torgash\Wishlist;

use App\Core\Privacy\PersonalData;
use App\Core\Privacy\Subject;
use Torgash\Wishlist\Models\WishList;

/**
 * The wishlist in core's personal-data registry (PLAN §49): an account's
 * lists — what they saved, and whether they asked to hear about price drops.
 * Erased whole; their items go with them (the foreign key cascades).
 */
class WishlistPersonalData
{
    public static function register(PersonalData $registry): void
    {
        $registry->register('wishlist', [
            'label' => 'Wishlist',
            'export' => fn (Subject $s) => $s->user
                ? WishList::where('user_id', $s->user->id)->with('items')->get()->map(fn (WishList $list) => [
                    'products' => $list->items->pluck('product_id')->all(),
                    'notify_price' => (bool) $list->notify_price,
                    'notify_stock' => (bool) $list->notify_stock,
                ])->all()
                : [],
            'erase' => fn (Subject $s) => $s->user ? WishList::where('user_id', $s->user->id)->delete() : 0,
        ]);
    }
}
