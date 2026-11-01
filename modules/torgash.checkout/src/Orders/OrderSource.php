<?php

namespace Torgash\Checkout\Orders;

/**
 * Which door an order came in by (`m_checkout_orders.source`), set once by
 * PlaceOrder and never changed: a manager editing a one-click order later
 * does not make it a manager's order.
 *
 * NULL on orders placed before the column existed — "not recorded", a
 * different answer from any of these.
 */
class OrderSource
{
    /** The storefront's checkout page. */
    public const CHECKOUT = 'checkout';

    /** The one-click dialog, from a product or from the basket. */
    public const ONE_CLICK = 'one_click';

    /** A manager's order in the admin. */
    public const ADMIN = 'admin';

    /** The shop API (a mobile app, a marketplace bridge). */
    public const API = 'api';

    /** The replacement sent for a return (torgash.rma). */
    public const EXCHANGE = 'exchange';

    /** @return array<string, string> code => English label, translated by lang files */
    public static function labels(): array
    {
        return [
            self::CHECKOUT => 'Checkout',
            self::ONE_CLICK => 'One click',
            self::ADMIN => 'By a manager',
            self::API => 'API',
            self::EXCHANGE => 'Exchange for a return',
        ];
    }

    public static function label(?string $source): string
    {
        return $source === null ? '' : __(self::labels()[$source] ?? $source);
    }
}
