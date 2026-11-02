<?php

namespace Torgash\Checkout\Shipping;

use Torgash\Checkout\Models\Order;

/**
 * How a delivery the recipient pays the carrier for is SAID (PLAN §66,
 * stage 4) — in the checkout's summary, the option lists, the order's
 * pages. The money is the carrier's: the shop charges 0 and keeps an
 * estimate, so every one of those places says "≈ 80, paid on receipt"
 * instead of "Free", which would be a promise the carrier does not keep.
 *
 * Here, in checkout, because an order says it with the shipping module gone.
 */
final class CarrierPaid
{
    public static function text(?int $estimate, callable $money): string
    {
        return $estimate === null
            ? __("at the carrier's rates, paid on receipt")
            : __('≈ :amount, paid to the carrier on receipt', ['amount' => $money($estimate)]);
    }

    /** For a quoted option — null when the shop delivers on its own account. */
    public static function forOption(?object $option, callable $money): ?string
    {
        return ($option->to_carrier ?? false) ? self::text($option->carrier_cost ?? null, $money) : null;
    }

    /** For a placed order — null when the shop delivers on its own account. */
    public static function forOrder(Order $order, callable $money): ?string
    {
        return $order->shipping_to_carrier ? self::text($order->shipping_carrier_cost, $money) : null;
    }
}
