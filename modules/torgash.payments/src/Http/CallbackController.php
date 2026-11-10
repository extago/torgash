<?php

namespace Torgash\Payments\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;
use Torgash\Checkout\Models\Order;
use Torgash\Payments\AnswersCallback;
use Torgash\Payments\Gateway;
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
    /** Unsigned messages kept per gateway and hour, and how much of each. */
    protected const UNVERIFIED_KEPT = 60;

    protected const UNVERIFIED_BYTES = 4096;

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

        $binding = $this->accountOf($request, $gateway, $handler);
        $config = $binding?->gatewayConfig() ?? [];

        // Nothing below the signature is read until it checks out: everything a
        // message says, its order included, is only as true as the signature.
        // No account means no secret, and an empty secret is one anybody knows.
        if (! $binding || ! $handler->verify($request, $config)) {
            $this->keepUnverified($request, $gateway);

            return response()->json(['ok' => true]);
        }

        $event = $handler->parse($request, $config);

        if (! $event) {
            Transaction::create([
                'gateway' => $gateway,
                'verified' => true,
                'applied' => false,
                'summary' => __('Nothing to act on'),
                'raw' => ['body' => $request->getContent()],
                'created_at' => now(),
            ]);

            return $this->answer($handler, $request, null, $config);
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
                'verified' => true,
                'applied' => false,
                'summary' => __('No such order'),
                'raw' => $event->raw,
                'created_at' => now(),
            ]);

            return $this->answer($handler, $request, $event, $config);
        }

        $this->applier->apply($gateway, $order, $event);

        return $this->answer($handler, $request, $event, $config);
    }

    /**
     * The account whose secret this message has to be signed with.
     *
     * A gateway can be wired to several payment methods, each with an account
     * of its own: a live one and a test one, two legal entities. A message has
     * to check out against the account its ORDER was placed through. The first
     * row's secret used to decide for all of them, which refused every message
     * meant for the others and let a message signed with a test account's
     * secret pay a live order.
     *
     * Which order it is can only be read from the message, before anybody
     * knows it is true. That is safe here because the claim only chooses which
     * secret is asked: a message lying about its order then has to be signed
     * by that order's own account. With one account nothing is read at all,
     * and whatever an unsigned body does to `parse()` stays this method's.
     *
     * Trying every account until one fits is the mistake this avoids: the test
     * account's secret would go on verifying messages about live orders.
     */
    protected function accountOf(Request $request, string $gateway, Gateway $handler): ?Binding
    {
        $accounts = Binding::where('gateway', $gateway)->orderBy('id')->get();

        if ($accounts->count() < 2) {
            return $accounts->first();
        }

        try {
            $claimed = $handler->parse($request, $accounts->first()->gatewayConfig())?->orderId;
        } catch (Throwable) {
            $claimed = null;
        }

        $order = $claimed ? Order::find($claimed) : null;

        // Naming no order it can do nothing, so any account will do to say so.
        if (! $order) {
            return $accounts->first();
        }

        $own = Binding::forOrder($order);

        return $own?->gateway === $gateway ? $own : null;
    }

    /**
     * A message whose signature does not check out says one true thing: that
     * somebody is knocking, perhaps the bank with a secret this shop has wrong.
     * So the person reading the log gets the text that arrived, and NOTHING is
     * lifted out of it onto the row. An order, an invoice id, "paid" and an
     * amount on a row are believed by whatever reads this table next: that is
     * how a forged callback became a real payment (`Ledger::started`), used up
     * the key of the genuine message, and could hand a gateway's own lookups a
     * forged id.
     *
     * Bounded, because anybody can post here and a row per post is a free way
     * to fill the table. The bound is on keeping them, so it can never cost a
     * genuine callback anything.
     */
    protected function keepUnverified(Request $request, string $gateway): void
    {
        RateLimiter::attempt('payments.unverified:'.$gateway, self::UNVERIFIED_KEPT, function () use ($request, $gateway) {
            $arrived = substr($request->getContent() ?: (string) $request->getQueryString(), 0, self::UNVERIFIED_BYTES);

            Transaction::create([
                'gateway' => $gateway,
                'verified' => false,
                'applied' => false,
                'summary' => __('Signature did not verify — ignored'),
                // jsonb refuses a broken sequence and a NUL; this text is anybody's.
                'raw' => ['body' => str_replace("\0", '', mb_scrub($arrived, 'UTF-8'))],
                'created_at' => now(),
            ]);
        }, 3600);
    }

    /**
     * What the gateway is told back after a message that verified. The ordinary
     * `{"ok": true}` for most; the gateway's own acknowledgement for one whose
     * protocol retries until it gets a particular signed answer
     * (AnswersCallback). A forgery never comes this way, since a signed reply
     * would teach its sender what our signature looks like.
     *
     * @param  array<string, mixed>  $config
     */
    protected function answer($handler, Request $request, ?\Torgash\Payments\PaymentEvent $event, array $config)
    {
        if ($handler instanceof AnswersCallback) {
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
