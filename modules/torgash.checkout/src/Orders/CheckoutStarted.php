<?php

namespace Torgash\Checkout\Orders;

use Torgash\Cart\CartEvents;
use Torgash\Cart\CartManager;
use Torgash\Catalog\Seo\ProductsEvent;

/**
 * A checkout begun, told to the page as `torgash:checkout-started` — a
 * counter's "began checkout" (Google Analytics' begin_checkout, PLAN §73).
 *
 * Raised by a script of the checkout page once it is parsed, with the
 * basket's lines as the basket's own events describe them (CartEvents), the
 * coupon and the books' currency. Not again when the form comes back
 * refused: that is the same checkout, not another one begun.
 */
class CheckoutStarted
{
    public const EVENT = 'torgash:checkout-started';

    /** The most products one event of Google Analytics takes. */
    public const MAX_LINES = 200;

    public static function announce(CartManager $cart, ?string $coupon = null): string
    {
        if (session()->hasOldInput()) {
            return '';
        }

        return ProductsEvent::script(self::EVENT, [
            'lines' => CartEvents::lines($cart->items(), null, self::MAX_LINES),
            'coupon' => $coupon ?: null,
            'currency' => shop_currency(),
        ]);
    }
}
