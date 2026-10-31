<?php

namespace Torgash\Promotions;

use Torgash\Cart\CartManager;

/**
 * Free delivery as a goal the customer can see — the basket's bar and the
 * product page's note (`partials/free-shipping`), both drawn from this one
 * answer. Whether it is to be had at all, and from what sum, is
 * CartPricing::freeShipping()'s: the same promotions, the same conditions,
 * the same subtotal (the goods, before a coupon) the price itself is
 * worked out from.
 *
 * A basket of goods nobody sends (CartManager::needsDelivery()) has no
 * delivery to make free and gets no note; an empty one is measured as
 * nothing, so the product page still names the sum.
 */
class FreeShippingGoal
{
    /**
     * @return array{active: bool, threshold: ?int, goods: int, missing: int}|null
     */
    public function state(): ?array
    {
        $cart = app(CartManager::class);
        $items = $cart->items();

        if ($items->isNotEmpty() && ! $cart->needsDelivery()) {
            return null;
        }

        $goods = (int) $items->sum('line_total');
        $answer = app(CartPricing::class)->freeShipping(BasketLines::of($items), $goods, auth()->user());

        if ($answer === null || (! $answer['active'] && $answer['threshold'] === null)) {
            return null;
        }

        return $answer + [
            'goods' => $goods,
            'missing' => $answer['active'] ? 0 : max(0, (int) $answer['threshold'] - $goods),
        ];
    }
}
