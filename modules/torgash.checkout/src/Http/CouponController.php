<?php

namespace Torgash\Checkout\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Cart\CartManager;
use Torgash\Checkout\CouponManager;

class CouponController extends Controller
{
    public function apply(Request $request, CouponManager $coupons, CartManager $cart)
    {
        $result = $coupons->apply((string) $request->input('code'));
        session()->flash('coupon_result', $result);

        return $this->answer($request, $cart);
    }

    public function remove(Request $request, CouponManager $coupons, CartManager $cart)
    {
        $coupons->remove();

        return $this->answer($request, $cart);
    }

    /**
     * The cart page swaps its contents; the CHECKOUT (`in=checkout`) has a
     * delivery step that prices the summary, so it is told the coupon changed
     * and asks for a fresh quote itself — drawing the summary here would be a
     * second place that knows how. A browser with no script goes back.
     */
    protected function answer(Request $request, CartManager $cart)
    {
        if ($request->input('in') === 'checkout') {
            return $request->header('HX-Request')
                ? response('', 200, ['HX-Trigger' => 'coupon-changed'])
                : redirect()->route('checkout.create');
        }

        return view('torgash.cart::partials.contents', ['cart' => $cart]);
    }
}
