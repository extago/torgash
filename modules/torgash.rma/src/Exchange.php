<?php

namespace Torgash\Rma;

use Illuminate\Validation\ValidationException;
use Torgash\Cart\CartManager;
use Torgash\Cart\Models\Cart;
use Torgash\Checkout\CouponManager;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\PlaceOrder;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Quote;
use Torgash\Rma\Models\ReturnRequest;

/**
 * The replacement order.
 *
 * An exchange is a NEW order (§29.3/3), and it goes through PlaceOrder like
 * every other one — the same rule OneClick follows for the same reason: an
 * order that is not an order has no stock, no statuses, no currencies and no
 * confirmation, and a shop ends up with two kinds of thing where it thought it
 * had one.
 *
 * The awkward part is not the order, it is WHO is placing it. PlaceOrder reads
 * identity from the ambient request — the logged-in user, the current
 * storefront, the locale, the currency being browsed in — which is exactly
 * right for a customer and exactly wrong for a manager pressing a button on
 * their behalf: the exchange would belong to the manager's account, sit in the
 * admin's storefront and be priced in whatever currency they last looked at.
 * That is what PlaceOrder's `$as` is for, and this is its first caller.
 *
 * What is NOT copied is the price. The cart is priced from today's catalogue,
 * as any order is; whether the difference is chased is a decision recorded on
 * the return (`surcharge`) rather than a payment this module invents.
 */
class Exchange
{
    public function __construct(protected PlaceOrder $orders) {}

    public function place(ReturnRequest $return): Order
    {
        $original = $return->order;

        if (! $original) {
            throw ValidationException::withMessages([
                'state' => __('The order this return is about no longer exists.'),
            ]);
        }

        $cart = $this->cartFor($return);

        try {
            $quote = $this->quote($cart, $original);
            $option = $this->option($quote, $original);
            $payment = $this->paymentMethod($original);

            return $this->orders->place(
                validated: [
                    'customer_name' => $original->customer_name,
                    'email' => $original->email,
                    'phone' => $original->phone,
                    'address_line' => $original->address_line,
                    'city' => $original->city,
                    'country' => $original->country,
                    'postal_code' => $original->postal_code,
                    'notes' => __('Exchange for order :number.', ['number' => $original->order_number]),
                    'shipping_method_id' => $option?->id,
                    'payment_method_id' => $payment->id,
                ],
                cart: $cart,
                coupons: app(CouponManager::class),
                location: $quote->location,
                quote: $quote,
                as: [
                    // The customer's, not the manager's — the whole reason `$as`
                    // exists. A guest order has no user_id and the exchange has
                    // none either, which is correct: it is the same person, and
                    // customer_ref is what pairs them.
                    'user_id' => $original->user_id,
                    'storefront_id' => $original->storefront_id,
                    'locale' => $original->locale,
                    // Frozen from the original: an exchange printed in a
                    // different currency shows the customer numbers they never
                    // agreed to.
                    'charge_currency' => $original->charge_currency,
                    'charge_rate' => $original->charge_rate,
                ],
            );
        } finally {
            // The throwaway cart goes whether the order was placed or refused —
            // a half-finished exchange must not leave a stray basket row behind.
            Cart::where('token', $cart->token())->delete();
        }
    }

    /**
     * A cart of its own holding what is being replaced, exactly as one click
     * builds one: the customer's real basket is not touched, ordered or
     * emptied by an act performed in the admin.
     */
    protected function cartFor(ReturnRequest $return): CartManager
    {
        $row = Cart::create([
            'token' => Cart::newToken(),
            'user_id' => null,
            'storefront_id' => $return->order?->storefront_id,
            'items' => [],
            'last_active_at' => now(),
        ]);

        // On the customer's behalf: what goes back out in an exchange is the
        // manager's decision, not held to what a customer could put in a basket.
        $cart = (new CartManager)->useToken($row->token)->onBehalf();

        foreach ($return->lines as $line) {
            $cart->add((int) $line['product_id'], max(1, (int) ($line['qty'] ?? 1)));
        }

        if ($cart->items()->isEmpty()) {
            throw ValidationException::withMessages([
                'state' => __('Nothing on this return is still in the catalogue, so there is nothing to exchange it for.'),
            ]);
        }

        return $cart;
    }

    /**
     * Delivered to where the original went. Quoted rather than copied: the
     * rates, the zones and the carrier's own answer may all have changed since,
     * and a frozen cost would be a promise nobody priced.
     */
    protected function quote(CartManager $cart, Order $original): Quote
    {
        $location = $original->place_id
            ? Location::forPlace($original->place_id, $original->postal_code)
            : new Location(country: $original->country, postal: $original->postal_code);

        $quote = hook_filter('checkout.delivery.quote', null, [
            'cart' => $cart,
            'location' => $location,
            'selected' => null,
        ]);

        return $quote instanceof Quote ? $quote : new Quote(
            location: $location,
            zone: null,
            options: collect(),
            selected: null,
            subtotal: $cart->total(),
            discount: 0,
        );
    }

    /**
     * The same delivery the original was sent by where it is still offered
     * here, and otherwise the first thing that is.
     *
     * Matched by NAME because that is what an order keeps — the id of a
     * shipping option is the shipping module's and is not frozen onto the row.
     * A shop with no shipping module at all gets null, which PlaceOrder accepts.
     */
    protected function option(Quote $quote, Order $original): ?object
    {
        if ($quote->options->isEmpty()) {
            if ($this->orders->shippingIsActive()) {
                throw ValidationException::withMessages([
                    'state' => __('Nothing can be delivered to that address right now, so the exchange order cannot be placed. Give a delivery method a rate that covers it, or place the order by hand.'),
                ]);
            }

            return null;
        }

        return $quote->options->first(
            fn (object $option) => ($option->label ?? null) === $original->shipping_method_name,
        ) ?? $quote->options->first();
    }

    /**
     * The method the original was sold under, while it still exists and is
     * still enabled — the manager changes it on the order screen like any
     * other. Refusing outright when there is none: an order has to be payable
     * somehow, and inventing a method here would hide a shop with none.
     */
    protected function paymentMethod(Order $original): PaymentMethod
    {
        $method = PaymentMethod::forOrder($original);

        if ($method && $method->enabled) {
            return $method;
        }

        $fallback = PaymentMethod::where('enabled', true)->orderBy('position')->first();

        if (! $fallback) {
            throw ValidationException::withMessages([
                'state' => __('There is no enabled payment method to place the exchange order with.'),
            ]);
        }

        return $fallback;
    }
}
