<?php

namespace Torgash\Payments\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Checkout\Models\Order;
use Torgash\Payments\AnswersCallback;
use Torgash\Payments\Gateways;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Models\Transaction;
use Torgash\Payments\PaymentApplier;
use Torgash\Payments\Starter;

/**
 * The server-to-server callback, and the customer's way back.
 *
 * These are two different things and only one of them is evidence:
 *
 * - **The callback** is the gateway talking to us. It carries a signature and it
 *   is the only thing that moves an order's payment state.
 * - **The return** is a browser coming back from a payment page. It moves
 *   nothing. A customer who closes the tab still gets paid-for goods, and a
 *   customer who forges the return URL gets nothing — which is the whole reason
 *   the two are separate. (The reference add-on gets this right: its redirect
 *   handler only routes to the order page.)
 */
class CallbackController extends Controller
{
    public function __construct(
        protected Gateways $gateways,
        protected PaymentApplier $applier,
        protected Starter $starter,
    ) {}

    /**
     * No session, no CSRF, no locale prefix: this URL is called by a machine.
     *
     * Always answers 200 once the gateway is known. A gateway that gets an error
     * retries, and retrying is right for "we were down" and pointless for "we do
     * not act on this message" — the transaction log is where the difference is
     * recorded, not the status code.
     */
    public function handle(Request $request, string $gateway)
    {
        $handler = $this->gateways->find($gateway);

        if (! $handler) {
            return response()->json(['error' => 'unknown gateway'], 404);
        }

        $binding = Binding::where('gateway', $gateway)->first();
        $config = $binding?->gatewayConfig() ?? [];

        $verified = $handler->verify($request, $config);
        $event = $handler->parse($request, $config);

        if (! $event) {
            Transaction::create([
                'gateway' => $gateway,
                'verified' => $verified,
                'applied' => false,
                'summary' => __('Nothing to act on'),
                'raw' => ['body' => $request->getContent()],
                'created_at' => now(),
            ]);

            return $this->answer($handler, $request, null, $config, $verified);
        }

        // The order comes from the SIGNED payload where the gateway names one.
        // Taking it from the query string means the signature protects the
        // contents but not the target, which is how a valid callback about one
        // order gets replayed at another.
        $order = $event->orderId ? Order::find($event->orderId) : null;

        if (! $order) {
            Transaction::create([
                'gateway' => $gateway,
                'external_id' => $event->externalId,
                'state' => $event->state,
                'verified' => $verified,
                'applied' => false,
                'summary' => __('No such order'),
                'raw' => $event->raw,
                'created_at' => now(),
            ]);

            return $this->answer($handler, $request, $event, $config, $verified);
        }

        $this->applier->apply($gateway, $order, $event, $verified);

        return $this->answer($handler, $request, $event, $config, $verified);
    }

    /**
     * What the gateway is told back. The ordinary `{"ok": true}` for most; the
     * gateway's own acknowledgement for one whose protocol retries until it
     * gets a particular signed answer (AnswersCallback) — but only when the
     * callback verified, since a signed reply to a forgery would teach its
     * sender what our signature looks like.
     *
     * @param  array<string, mixed>  $config
     */
    protected function answer($handler, Request $request, ?\Torgash\Payments\PaymentEvent $event, array $config, bool $verified)
    {
        if ($verified && $handler instanceof AnswersCallback) {
            $response = $handler->callbackResponse($request, $event, $config);

            if ($response !== null) {
                return $response;
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * The customer pressing "Pay" — starting, or resuming, the payment.
     *
     * Resuming is not a nicety: an invoice has a lifetime, customers close the
     * tab, and without this the only way back to a payment page is placing the
     * order again. Linked from the confirmation page and the order email.
     *
     * Every way this can fail ends on the confirmation page WITH A REASON.
     * Before, all of them ended there silently: a refused invoice, a gateway
     * that had been unbound, an API that was down and an order that simply
     * takes no online payment were one and the same button that did nothing,
     * and the only trace was a row in a log the customer cannot see (§48).
     */
    public function pay(Order $order)
    {
        // The confirmation page is guarded by "you just placed this" — and a
        // customer arriving from tomorrow's email has not. They did hold a
        // signed link for this order, which is the same proof, so the session
        // is told so before they are sent back; otherwise every failure landed
        // them on a 404 instead of on the reason.
        session(['last_order_number' => $order->order_number]);

        $back = redirect()->route('checkout.confirmation', $order->order_number);

        if (! $this->gateways->forOrder($order)) {
            // The method lost its gateway between the order being placed and
            // the customer coming back — nothing they can do, but "nothing
            // happens" is not an answer either.
            return $back->with('payment_error', __('This order cannot be paid online any more. Please contact us.'));
        }

        // Everything else — a refusal, an outage, a gateway that returns no
        // page — is recorded and explained by the Starter, which the redirect
        // straight after checkout goes through as well.
        $url = $this->starter->start($order);

        return $url ? redirect()->away($url) : $back;
    }
}
