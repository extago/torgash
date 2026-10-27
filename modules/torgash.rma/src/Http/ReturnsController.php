<?php

namespace Torgash\Rma\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\OrderKey;
use Torgash\Rma\RequestReturn;

class ReturnsController extends Controller
{
    /**
     * Asking for a return, from the order page.
     *
     * Authorised exactly as reading the order is: your account's, the session
     * that placed it, or its signed link. The check is repeated here rather
     * than trusted from the page that drew the form — a POST arrives on its
     * own, and "they must have come from there" is not an authorisation.
     */
    public function store(Request $request, Order $order, RequestReturn $returns)
    {
        $this->authorizeCustomer($request, $order);

        $returns->handle($request, $order);

        return back()->with('status', __('Thank you — we will be in touch about your return.'));
    }

    protected function authorizeCustomer(Request $request, Order $order): void
    {
        $isOwnOrder = auth()->check() && $order->user_id === auth()->id();
        $justPlaced = $request->session()->get('last_order_number') === $order->order_number;

        abort_unless($isOwnOrder || $justPlaced || OrderKey::matches($request, $order, 'rma.request'), 404);
    }
}
