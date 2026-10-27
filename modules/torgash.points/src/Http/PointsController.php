<?php

namespace Torgash\Points\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Cart\CartManager;
use Torgash\Points\Ledger;
use Torgash\Points\Models\Entry;
use Torgash\Points\Models\OrderPoints;
use Torgash\Points\Spending;

class PointsController extends Controller
{
    /** "Spend N points" — blank or 0 spends none. */
    public function spend(Request $request, CartManager $cart, Spending $spending)
    {
        $spending->ask($cart, max(0, (int) preg_replace('/\D/', '', (string) $request->input('points', ''))));

        return $this->answer($request, $cart);
    }

    /** A line bought outright for its price in points — or no longer. */
    public function toggleLine(Request $request, string $key, CartManager $cart, Spending $spending)
    {
        $spending->toggleLine($cart, $key);

        return $this->answer($request, $cart);
    }

    /** The customer's balance, what is waiting, and the history. */
    public function account(Ledger $ledger)
    {
        $userId = auth()->id();

        return view('torgash.points::account.index', [
            'balance' => $ledger->balance($userId),
            'waiting' => (int) OrderPoints::where('user_id', $userId)->whereNull('credited_at')->where('earn', '>', 0)->sum('earn'),
            'entries' => Entry::where('user_id', $userId)->orderByDesc('id')->paginate(30),
            'orders' => \Torgash\Checkout\Models\Order::whereIn('id', Entry::where('user_id', $userId)->whereNotNull('order_id')->select('order_id'))
                ->pluck('order_number', 'id'),
            'pointValue' => app(Spending::class)->pointValue(),
        ]);
    }

    /** As the coupon and the gift cards answer: the cart swaps, the checkout re-quotes. */
    protected function answer(Request $request, CartManager $cart)
    {
        if ($request->input('in') === 'checkout') {
            return $request->header('HX-Request')
                ? response('', 200, ['HX-Trigger' => 'coupon-changed'])
                : redirect()->route('checkout.create');
        }

        return $request->header('HX-Request')
            ? view('torgash.cart::partials.contents', ['cart' => $cart])
            : redirect()->route('cart.index');
    }
}
