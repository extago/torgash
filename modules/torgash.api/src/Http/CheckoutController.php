<?php

namespace Torgash\Api\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Torgash\Cart\CartManager;
use Torgash\Cart\Models\Cart;
use Torgash\Checkout\CouponManager;
use Torgash\Checkout\Orders\PlaceOrder;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Quote;

/**
 * Checkout as a conversation with a cart.
 *
 * A browser answers everything on one page and submits once. A client cannot:
 * it has to give an address before it can be told what delivery costs, and
 * choose a delivery before it knows the total. So each answer is PATCHed onto
 * the cart, the options for the answers so far are readable at any point, and
 * `complete` places the order from what is stored (§15.1.2).
 *
 * What it does NOT do is decide anything. Every rule about what an order is —
 * which delivery may be chosen, which payment method this currency allows, when
 * stock moves, what is frozen onto the row — comes from `PlaceOrder`, the same
 * object the storefront's own form goes through. Placing an order two ways is
 * how two shops end up in one codebase.
 */
class CheckoutController extends Controller
{
    /** The answers so far, and what may be answered next. */
    public function show(Request $request, string $token)
    {
        $cart = $this->cart($token);

        return response()->json(['data' => $this->body($cart)]);
    }

    /**
     * Answer one step, or several. Merged onto what is already there, so a
     * client that only knows the address does not have to resend it when it
     * later picks a delivery.
     */
    public function update(Request $request, string $token)
    {
        $cart = $this->cart($token);

        $validated = $request->validate([
            'customer_name' => 'nullable|string|max:190',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:40',
            'address_line' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:120',
            'country' => 'nullable|string|size:2',
            'postal_code' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:1000',
            'place_id' => 'nullable|integer',
            'shipping_method_id' => 'nullable|string',
            'shipping_fields' => 'nullable|array',
            'payment_method_id' => 'nullable|integer',
            // A coupon or a gift card - one box, as on the page; and taking a
            // coupon or a prepaid line back off.
            'code' => 'nullable|string|max:64',
            'remove_coupon' => 'nullable|boolean',
            'remove_prepaid' => 'nullable|integer',
        ]);

        $codeResult = null;
        $coupons = new CouponManager($cart);

        if (! empty($validated['code'])) {
            $codeResult = $coupons->apply($validated['code']);
        }

        if (! empty($validated['remove_coupon'])) {
            $coupons->remove();
        }

        if (! empty($validated['remove_prepaid'])) {
            hook_filter('checkout.prepaid.remove', null, ['cart' => $cart, 'id' => (int) $validated['remove_prepaid']]);
        }

        unset($validated['code'], $validated['remove_coupon'], $validated['remove_prepaid']);

        // Deliberately not validated against what is on offer YET: an address
        // typed one field at a time is briefly nonsense, and refusing it would
        // make a form impossible to build. `complete` is where the answers have
        // to add up — and it validates them with the storefront's own rules.
        $cart->setCheckout($validated);

        return response()->json(array_filter([
            'data' => $this->body($cart),
            // What the code box said, when a code was sent.
            'code' => $codeResult,
        ], fn ($value) => $value !== null));
    }

    /**
     * Place the order from the answers on the cart.
     *
     * Everything is re-derived here and nothing is trusted from the client: the
     * quote is recomputed for the stored address, so a delivery option with no
     * rate in the winning zone cannot be bought however the cart was PATCHed.
     */
    public function complete(Request $request, string $token, PlaceOrder $placer, CouponManager $coupons)
    {
        $cart = $this->cart($token);

        if ($cart->items()->isEmpty()) {
            return response()->json(['message' => __('The cart is empty.')], 422);
        }

        $answers = $cart->checkout();
        $location = $this->location($answers);
        $quote = $this->quote($cart, $coupons, $location, $answers['shipping_method_id'] ?? null);

        $validator = Validator::make(
            $answers,
            $placer->rules($quote, $answers['shipping_method_id'] ?? null, $cart->needsDelivery()),
        );

        if ($validator->fails()) {
            // 422 with the field names the client PATCHed: "what is still
            // missing" is the question a checkout screen asks all the time.
            return response()->json([
                'message' => __('The checkout is not complete.'),
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $order = $placer->place(
            $validator->validated(),
            $cart,
            $coupons,
            $location,
            $quote,
            (array) ($answers['shipping_fields'] ?? []),
        );

        // Where a browser would be redirected: a gateway's own page. A client
        // opens it in a web view and comes back — there is no way to take a
        // card without one, and pretending otherwise would be inventing a
        // second payment flow.
        $payment = hook_filter('checkout.after_place_redirect', null, ['order' => $order]);

        return response()->json([
            'data' => [
                'order' => [
                    'number' => $order->order_number,
                    'status' => $order->status,
                    'total' => $order->total,
                    'currency' => $order->currency_code,
                    'payment' => [
                        'state' => $order->payment_state,
                        'currency' => $order->paymentCurrency(),
                        // What is left after a gift card: the bank's part.
                        'amount' => $order->chargeMinor($order->chargeNow()),
                        'prepaid' => $order->prepaid_total,
                        'url' => $payment,
                    ],
                ],
            ],
        ], 201);
    }

    // ---------------------------------------------------------------- helpers

    protected function cart(string $token): CartManager
    {
        abort_unless(Cart::where('token', $token)->exists(), 404, __('No such cart.'));

        return app(CartManager::class)->useToken($token);
    }

    /** @param array<string, mixed> $answers */
    protected function location(array $answers): Location
    {
        // A Location is a place in the registry plus a country/postcode — the
        // town NAME is on the order, not here: zones match on the settlement,
        // which is why `place_id` is what a client should send.
        return new Location(
            country: $answers['country'] ?? null,
            placeId: isset($answers['place_id']) ? (int) $answers['place_id'] : null,
            postal: $answers['postal_code'] ?? null,
        );
    }

    /**
     * The delivery on offer for these answers — asked of the shipping module
     * exactly as the checkout page asks it, so an app is quoted what a browser
     * would be.
     */
    protected function quote(CartManager $cart, CouponManager $coupons, Location $location, int|string|null $selected): Quote
    {
        $quote = hook_filter('checkout.delivery.quote', null, [
            'cart' => $cart,
            'location' => $location,
            'selected' => $selected,
        ]);

        return $quote instanceof Quote ? $quote : new Quote(
            location: $location,
            zone: null,
            options: collect(),
            selected: null,
            subtotal: $cart->total(),
            discount: $coupons->discount(),
        );
    }

    /** @return array<string, mixed> */
    protected function body(CartManager $cart): array
    {
        $answers = $cart->checkout();
        $location = $this->location($answers);
        $quote = $this->quote($cart, app(CouponManager::class), $location, $answers['shipping_method_id'] ?? null);
        $placer = app(PlaceOrder::class);

        return [
            'answers' => $answers,
            // Priced for the address given so far, which is why the address is
            // the first question: an option's cost is a property of where it is
            // going.
            'delivery' => $quote->options->map(fn ($option) => [
                'id' => (string) $option->id,
                'label' => $option->label,
                // Null when a carrier answered but named no price — the shop
                // will quote it by hand, and a client must not print "free".
                'cost' => $option->cost,
                'delivery_time' => $option->delivery_time ?? null,
                'needs_address' => $option->needs_address ?? true,
                // A provider option that needs more from the customer (a branch
                // to collect from) says so; the browser renders a blade for it,
                // a client has to be told in data.
                'needs_fields' => (bool) ($option->fields_view ?? null),
                'meta' => $option->meta ?? [],
            ])->values(),
            'payment' => $placer->paymentMethods()->map(fn ($method) => [
                'id' => $method->id,
                'label' => $method->t('name'),
                // What the bank will be asked in, when the method cannot take
                // what the customer is shopping in.
                'charged_in' => $method->chargeCurrencyFor(current_currency()),
            ])->values(),
            'currency' => current_currency(),
            'subtotal' => $quote->subtotal,
            'discount' => $quote->discount,
            // The coupon and what comes off after it (bonus points), line by line.
            'discounts' => $this->discountLines($cart),
            'total' => $grand = $this->grand($cart, $quote, $answers),
            // Paid before any bank is asked - a gift card on the cart - and
            // what is left.
            'prepaid' => $prepaid = array_map(fn (array $line) => array_intersect_key($line, array_flip(['label', 'amount', 'card_id'])),
                app(\Torgash\Checkout\Totals::class)->prepaidLines($cart, $grand, $quote->shippingCost())),
            'to_pay' => max(0, $grand - array_sum(array_column($prepaid, 'amount'))),
        ];
    }

    /** @return list<array{code: string, label: string, amount: int}> */
    protected function discountLines(CartManager $cart): array
    {
        $coupons = new CouponManager($cart);
        $lines = [];

        if ($coupon = $coupons->current()) {
            $lines[] = ['code' => 'coupon', 'label' => __('Coupon').' ('.$coupon->code.')', 'amount' => $coupons->couponDiscount()];
        }

        foreach ($coupons->otherDiscounts() as $line) {
            $lines[] = ['code' => (string) $line['code'], 'label' => (string) $line['label'], 'amount' => (int) $line['amount']];
        }

        return $lines;
    }

    /** The bill as the page would print it: goods after discounts, delivery, and what other modules add. */
    protected function grand(CartManager $cart, Quote $quote, array $answers): int
    {
        $totals = app(\Torgash\Checkout\Totals::class);

        return $totals->grand($quote, $totals->extra($cart, $quote, isset($answers['payment_method_id']) ? (string) $answers['payment_method_id'] : null));
    }
}
