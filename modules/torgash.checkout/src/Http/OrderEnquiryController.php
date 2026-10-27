<?php

namespace Torgash\Checkout\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Checkout\Enquiries\OrderEnquiries;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\OrderKey;
use Torgash\Enquiries\Submit;

/**
 * Asking a question about your own order.
 *
 * Authorised exactly as reading the order is — your account's, the session that
 * placed it, or its signed link — and repeated here rather than trusted from
 * the page that drew the form: a POST arrives on its own, and "they must have
 * come from there" is not an authorisation.
 *
 * The subject is set from the order this route was addressed with, never read
 * from the request, so a hand-built form cannot file a question against
 * somebody else's order.
 */
class OrderEnquiryController extends Controller
{
    public function store(Request $request, Order $order)
    {
        abort_unless(OrderEnquiries::available(), 404);

        $this->authorizeCustomer($request, $order);

        // Through the enquiries funnel like every other form: the spam guard,
        // the label snapshot, the customer ref, the shop's notification and the
        // automations trigger are the ones every enquiry gets.
        app(Submit::class)->handle($request, OrderEnquiries::KIND, [
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'page_url' => $order->customerUrl(),
        ]);

        return back()->with('status', __('Thank you — we will be in touch.'));
    }

    protected function authorizeCustomer(Request $request, Order $order): void
    {
        $isOwnOrder = auth()->check() && $order->user_id === auth()->id();
        $justPlaced = $request->session()->get('last_order_number') === $order->order_number;

        abort_unless($isOwnOrder || $justPlaced || OrderKey::matches($request, $order, 'checkout.enquiry'), 404);
    }
}
