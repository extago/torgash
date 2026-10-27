<?php

namespace Torgash\Payments\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Checkout\Models\Order;
use Torgash\Payments\Gateways;
use Torgash\Payments\Jobs\ReconcilePayments;
use Torgash\Payments\Models\Payment;
use Torgash\Payments\Models\Transaction;
use Torgash\Payments\PaymentActions;
use Torgash\Payments\PaymentApplier;

/**
 * What is left of an admin screen once configuring a gateway moved to the
 * payment method it belongs to: the log, and the buttons an order posts to.
 */
class GatewaysController extends Controller
{
    public function __construct(protected Gateways $gateways, protected PaymentApplier $applier) {}

    /**
     * A button from the order screen: re-ask the gateway, capture a hold, refund,
     * release, cancel — about ONE payment (`payment`), since an order can have
     * several (a deposit and its rest). The result goes through the same
     * applier a callback does — a refund done from here must land in the order
     * exactly as one the gateway announces later. With no payment named, the
     * order's latest is meant: "Refresh" on an order whose invoice we have not
     * heard about yet.
     */
    public function act(Request $request, Order $order, string $capability, PaymentActions $actions)
    {
        $payment = $request->filled('payment')
            ? Payment::where('order_id', $order->id)->findOrFail((int) $request->input('payment'))
            : Payment::where('order_id', $order->id)->latest('id')->first();

        if ($payment && ! in_array($capability, $actions->available($payment), true)) {
            return back()->with('status', __('That gateway cannot do this.'));
        }

        if (! $payment) {
            return $this->actOnOrder($order, $capability);
        }

        $amount = $request->filled('amount') ? (int) $request->input('amount') : null;
        $event = $actions->act($payment, $capability, $amount && $amount < $payment->held() ? $amount : null);

        if (! $event) {
            return back()->with('status', __('The gateway said nothing useful. See the payment log.'));
        }

        return back()->with('status', __('Done: :summary', ['summary' => $event->summary ?? $event->state]));
    }

    /** An order with no payment recorded yet — only the gateway can say. */
    protected function actOnOrder(Order $order, string $capability)
    {
        $found = $this->gateways->forOrder($order);

        if (! $found || ! in_array($capability, $found['gateway']->capabilities(), true)) {
            return back()->with('status', __('That gateway cannot do this.'));
        }

        $event = $found['gateway']->act($capability, $order, $found['binding']->gatewayConfig());

        if (! $event) {
            return back()->with('status', __('The gateway said nothing useful. See the payment log.'));
        }

        $this->applier->apply($found['binding']->gateway, $order, $event);

        return back()->with('status', __('Done: :summary', ['summary' => $event->summary ?? $event->state]));
    }

    /**
     * Run the pending-payment check now rather than waiting for the scheduler.
     *
     * Queued like the scheduled one — not run inline — so one button press
     * cannot sit there making an API call per order while the admin waits.
     */
    public function reconcile()
    {
        $task = ReconcilePayments::startIfIdle();

        return back()->with('status', $task
            ? __('Asking the gateways. Watch it under System → Background tasks.')
            : __('Already running.'));
    }

    public function transactions(Request $request)
    {
        return view('torgash.payments::admin.transactions', [
            'transactions' => Transaction::query()
                ->when($request->filled('order'), fn ($q) => $q->where('order_id', (int) $request->get('order')))
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
        ]);
    }
}
