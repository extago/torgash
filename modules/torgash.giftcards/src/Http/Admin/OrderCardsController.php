<?php

namespace Torgash\Giftcards\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Checkout\Models\Order;
use Torgash\Giftcards\Models\Card;
use Torgash\Giftcards\OrderCards;

/**
 * A card put on a placed order by a manager — "the customer forgot to use it"
 * — and taken back off. Both need the right to edit orders as well as this
 * module's own: it moves an order's money.
 */
class OrderCardsController extends Controller
{
    public function attach(Request $request, Order $order, OrderCards $orderCards)
    {
        abort_unless($request->user()?->can('orders.manage'), 403);

        $data = $request->validate([
            'code' => 'required|string|max:40',
            'limit' => 'nullable|numeric|min:0.01',
        ]);

        $card = Card::findByCode($data['code']);

        if (! $card) {
            return back()->withErrors(['giftcard_code' => __('There is no gift card with this code.')]);
        }

        $result = $orderCards->attach($order, $card, isset($data['limit']) ? (int) round((float) $data['limit'] * 100) : null);

        return is_string($result)
            ? back()->withErrors(['giftcard_code' => $result])
            : back()->with('status', __(':amount paid with the gift card :card.', [
                'amount' => admin_price($result, $order->currency_code),
                'card' => $card->masked(),
            ]));
    }

    public function detach(Request $request, Order $order, Card $card, OrderCards $orderCards)
    {
        abort_unless($request->user()?->can('orders.manage'), 403);

        $given = $orderCards->detach($order, $card);

        return back()->with('status', __(':amount went back onto the gift card.', ['amount' => admin_price($given, $order->currency_code)]));
    }
}
