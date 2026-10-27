<?php

namespace Torgash\Wishlist;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Torgash\Wishlist\Models\WishList;

/**
 * What a letter about a list needs and a worker cannot ask a request for: a
 * link in the reader's own locale shape, money in their shop's currency, and
 * the way out of the letters.
 */
class Letters
{
    /**
     * `route()` in a worker has no request to take the shape from, so a bare
     * name sends a Russian reader to the English page — the prefixed name is
     * asked for when the list was kept in another language than its shop's.
     */
    public static function url(WishList $list, string $name, mixed $parameters = []): string
    {
        $locale = $list->locale;

        if ($locale && $locale !== $list->storefront?->default_locale && Route::has($locale.'.'.$name)) {
            $name = $locale.'.'.$name;
        }

        return route($name, $parameters);
    }

    /** In the books of the shop the list was kept in — a worker has no storefront. */
    public static function money(WishList $list, int $minor): string
    {
        return format_price($minor, $list->storefront?->default_currency ?? shop_currency());
    }

    /**
     * Switching one letter off from inside it: a SIGNED address, because the
     * reader of a letter is not necessarily signed in on the device they read
     * it on, and must not have to be to stop receiving it.
     */
    public static function unsubscribeUrl(WishList $list, string $what): string
    {
        return URL::signedRoute('wishlist.unsubscribe', ['list' => $list->id, 'what' => $what]);
    }

    public static function image(?string $url): ?string
    {
        return $url ? url($url) : null;
    }
}
