<?php

namespace Torgash\Shipping\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Cart\CartManager;
use Torgash\Checkout\Shipping\LocationStore;
use Torgash\Shipping\Quoter;

/**
 * Re-quotes delivery when the customer says where they are (or changes their
 * mind about how).
 *
 * Both screens post here rather than each computing their own answer in
 * JavaScript: the price of a method depends on the zone, the weight and the
 * coupon, and a browser-side copy of that arithmetic is a second implementation
 * waiting to disagree with the one that actually charges the card.
 */
class DeliveryController extends Controller
{
    /** The cart page's estimate block, swapped in place. */
    public function estimate(Request $request, CartManager $cart, LocationStore $store, Quoter $quoter)
    {
        $location = $store->fromRequest($request);

        return view('torgash.shipping::partials.cart-delivery', [
            'quote' => $quoter->for($cart, $location),
            'town' => $store->town(),
        ]);
    }

    /**
     * The checkout form's shipping section, plus the order summary that hangs
     * off it — the summary comes back as an out-of-band swap because the two
     * sit in different columns and must never show different prices.
     */
    public function quote(Request $request, CartManager $cart, LocationStore $store, Quoter $quoter)
    {
        $location = $store->fromRequest($request);

        return view('torgash.shipping::partials.quote-fragments', [
            'cart' => $cart,
            'quote' => $quoter->for($cart, $location, $request->input('shipping_method_id')),
        ]);
    }
}
