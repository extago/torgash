<?php

namespace Torgash\Stripe;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\Charge;
use Torgash\Payments\Gateway;
use Torgash\Payments\Models\Transaction;
use Torgash\Payments\PaymentEvent;
use Torgash\Payments\PaymentFailed;

/**
 * Stripe, through a hosted Checkout Session.
 *
 * Written from Stripe's published API reference and webhook guide. What is
 * particular to it:
 *
 * - **The session has no payment until the customer pays** (`payment_intent`
 *   is null at creation), so the order is written into the metadata of BOTH:
 *   the session's, for the session events, and — through
 *   `payment_intent_data[metadata]` — the PaymentIntent's, for the payment
 *   events. Both arrive inside the signed body; the callback URL names
 *   nothing. A refund arrives as a CHARGE, which carries the PaymentIntent's
 *   id from that same signed body, and the order is found through this
 *   shop's own log of which payment was whose.
 * - **Every webhook is its own event with its own id** (`evt_…`), which is
 *   exactly what the deduplication wants as its key.
 * - **Releasing a hold is not a refund**: `payment_intents/{id}/cancel`
 *   lets the money go without it ever leaving the card, `refunds` sends back
 *   money that did. This is the first gateway here where the two differ, so
 *   it is the first to declare `void`.
 * - **Zero- and three-decimal currencies are refused**: `Charge` is in
 *   hundredths, and Stripe bills yen in yen. Sending 100_00 as JPY would be a
 *   charge a hundred times too large.
 */
class StripeGateway implements Gateway
{
    /**
     * The languages Stripe Checkout draws its page in — the customer's own
     * when Stripe has it, Stripe's guess otherwise. Theirs to list, not ours.
     */
    public const CHECKOUT_LOCALES = ['bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fil', 'fr', 'hr', 'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'ms', 'mt', 'nb', 'nl', 'pl', 'pt', 'ro', 'ru', 'sk', 'sl', 'sv', 'th', 'tr', 'vi', 'zh'];

    /** Currencies whose smallest unit is not a hundredth — see the class docblock. */
    protected const NOT_HUNDREDTHS = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        'BHD', 'JOD', 'KWD', 'OMR', 'TND',
    ];

    public static function key(): string
    {
        return 'stripe';
    }

    public static function label(): string
    {
        return 'Stripe';
    }

    public function fields(): array
    {
        return [
            'secret_key' => [
                'label' => 'Secret key',
                'type' => 'password',
                'help' => 'dashboard.stripe.com → Developers → API keys. sk_test_… while testing, sk_live_… for real money.',
            ],
            'webhook_secret' => [
                'label' => 'Webhook signing secret',
                'type' => 'password',
                'help' => 'whsec_…, shown when you add this shop\'s webhook endpoint in Stripe. Without it no webhook can be trusted and no order is ever marked paid by itself.',
            ],
            'hold' => [
                'label' => 'Authorize first, take the money later',
                'type' => 'checkbox',
                'help' => 'The card is held and nothing is taken until you press Capture on the order. Stripe lets a hold stand for about seven days.',
            ],
        ];
    }

    /** Stripe takes well over a hundred currencies; the ones it cannot are refused in start(). */
    public function currencies(): ?array
    {
        return null;
    }

    public function warnings(array $config): array
    {
        $key = (string) ($config['secret_key'] ?? '');
        $testMode = ! empty($config['test_mode']);
        $warnings = [];

        if (str_starts_with($key, 'sk_test_') && ! $testMode) {
            $warnings[] = __('The secret key is a TEST key, and test mode is off: every payment will be play money. Paste the live key, or turn test mode on so the screen says so.');
        } elseif (str_starts_with($key, 'sk_live_') && $testMode) {
            $warnings[] = __('The secret key is a LIVE key, and test mode is on: payments are real money. Turn test mode off, or paste the test key.');
        }

        if ($key !== '' && ($config['webhook_secret'] ?? '') === '') {
            $warnings[] = __('No webhook signing secret: Stripe\'s notifications cannot be verified, so no order is ever marked paid by itself.');
        }

        return $warnings;
    }

    // ------------------------------------------------------------------ start

    public function start(Order $order, Charge $charge, array $config): ?string
    {
        $currency = strtoupper($charge->currency);

        if (in_array($currency, self::NOT_HUNDREDTHS, true)) {
            throw new PaymentFailed(__(
                'Stripe bills :currency in whole units, and this shop counts in hundredths. Give this payment method another currency to charge in.',
                ['currency' => $currency],
            ));
        }

        if (($config['secret_key'] ?? '') === '') {
            throw new PaymentFailed(__('The Stripe secret key is not set on this payment method.'));
        }

        $back = route('checkout.confirmation', $order->order_number);
        $metadata = ['order_id' => (string) $order->id, 'order_number' => $order->order_number];

        $payload = array_filter([
            'mode' => 'payment',
            'client_reference_id' => (string) $order->id,
            'metadata' => $metadata,
            'payment_intent_data' => array_filter([
                'metadata' => $metadata,
                'capture_method' => ! empty($config['hold']) ? 'manual' : null,
                'description' => __('Order :number', ['number' => $order->order_number]),
            ]),
            // One line, the order: its own lines are in the shop's books and
            // the charge may be in another currency, and Stripe's total is the
            // sum of its lines.
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $charge->amount,
                    'product_data' => ['name' => __('Order :number', ['number' => $order->order_number])],
                ],
            ]],
            'customer_email' => $order->email ?: null,
            'locale' => in_array($order->locale, self::CHECKOUT_LOCALES, true) ? $order->locale : 'auto',
            'success_url' => $back,
            'cancel_url' => $back,
        ]);

        $response = $this->client($config)->post('checkout/sessions', $payload);
        $body = $response->json() ?? [];

        if (! $response->successful() || empty($body['url'])) {
            throw new PaymentFailed($this->error($response));
        }

        Transaction::create([
            'gateway' => self::key(),
            'order_id' => $order->id,
            'external_id' => $body['id'] ?? null,
            'state' => PaymentState::PENDING,
            'amount' => $order->chargeNow(),
            'verified' => true,
            'applied' => false,
            'summary' => __('Checkout session created'),
            'raw' => ['request' => $payload, 'response' => ['id' => $body['id'] ?? null, 'url' => $body['url']]],
            'created_at' => now(),
        ]);

        return $body['url'];
    }

    // --------------------------------------------------------------- callback

    public function verify(Request $request, array $config): bool
    {
        return Client::verifyWebhook(
            $request->getContent(),
            (string) $request->header('Stripe-Signature'),
            (string) ($config['webhook_secret'] ?? ''),
        );
    }

    /**
     * The events this shop listens to, each read into one of ours. Anything
     * else is "nothing to act on" — the endpoint may be subscribed to more
     * than this gateway needs.
     */
    public function parse(Request $request, array $config): ?PaymentEvent
    {
        $event = json_decode($request->getContent(), true);
        $object = $event['data']['object'] ?? null;

        if (! is_array($event) || ! is_array($object) || ! isset($event['type'])) {
            return null;
        }

        $result = match ($event['type']) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => ($object['payment_status'] ?? null) === 'paid'
                ? [PaymentState::PAID, $object['amount_total'] ?? null, $object['payment_intent'] ?? $object['id']]
                : null,
            'checkout.session.async_payment_failed' => [PaymentState::FAILED, 0, $object['payment_intent'] ?? $object['id']],
            'checkout.session.expired' => [PaymentState::EXPIRED, 0, $object['id']],
            'payment_intent.amount_capturable_updated',
            'payment_intent.succeeded',
            'payment_intent.canceled',
            'payment_intent.payment_failed' => $this->fromIntent($object, $this->orderOf($object)),
            'charge.refunded' => $this->fromCharge($object),
            default => null,
        };

        if ($result === null) {
            return null;
        }

        [$state, $amount, $externalId] = $result;

        return new PaymentEvent(
            state: $state,
            amount: $amount === null ? null : (int) $amount,
            externalId: $externalId,
            eventKey: isset($event['id']) ? (string) $event['id'] : null,
            summary: trim($event['type'].' '.($object['last_payment_error']['message'] ?? '')),
            raw: $event,
            orderId: $this->orderOf($object),
        );
    }

    /**
     * A PaymentIntent in our words.
     *
     * A declined attempt is NOT a failed payment: the customer is still on
     * Stripe's page and may try another card in the same session. It is
     * recorded as pending with the reason, so the log shows it and the order
     * does not read "failed" under a customer who is about to pay.
     *
     * @param  array<string, mixed>  $intent
     * @return array{0: string, 1: ?int, 2: ?string}
     */
    protected function fromIntent(array $intent, ?int $orderId = null): array
    {
        $refunded = (int) ($intent['latest_charge']['amount_refunded'] ?? 0);
        $received = (int) ($intent['amount_received'] ?? 0);

        $state = match ($intent['status'] ?? null) {
            'requires_capture' => [PaymentState::AUTHORIZED, (int) ($intent['amount_capturable'] ?? 0)],
            'succeeded' => match (true) {
                $refunded === 0 => [PaymentState::PAID, $received],
                $refunded >= $received => [PaymentState::REFUNDED, 0],
                default => [PaymentState::PARTIALLY_REFUNDED, $received - $refunded],
            },
            'canceled' => [$this->wasHeld($orderId, $intent['id'] ?? null) ? PaymentState::REFUNDED : PaymentState::EXPIRED, 0],
            default => [PaymentState::PENDING, null],
        };

        return [$state[0], $state[1], $intent['id'] ?? null];
    }

    /**
     * A cancelled PaymentIntent is two different things. Cancelled while HELD
     * it is money released back to the customer — REFUNDED, at zero, which the
     * applier accepts from `authorized` as it would any refund. Cancelled
     * before anything was paid it is simply over — EXPIRED. Reading both as
     * EXPIRED would leave a released hold showing as held for ever, since the
     * applier will not step back from `authorized` to `expired`.
     */
    protected function wasHeld(?int $orderId, ?string $intent = null): bool
    {
        // The payment's own story first — an order can hold one invoice and
        // be waiting on another — then the order's, for a payment we have
        // no row for.
        $own = $intent === null ? null : \Torgash\Payments\Models\Payment::where('gateway', self::key())
            ->where('external_id', $intent)->value('state');

        if ($own !== null) {
            return $own === PaymentState::AUTHORIZED;
        }

        return $orderId !== null
            && Order::whereKey($orderId)->value('payment_state') === PaymentState::AUTHORIZED;
    }

    /**
     * @param  array<string, mixed>  $charge
     * @return array{0: string, 1: int, 2: ?string}
     */
    protected function fromCharge(array $charge): array
    {
        $amount = (int) ($charge['amount'] ?? 0);
        $refunded = (int) ($charge['amount_refunded'] ?? 0);

        return $refunded >= $amount
            ? [PaymentState::REFUNDED, 0, $charge['payment_intent'] ?? null]
            : [PaymentState::PARTIALLY_REFUNDED, $amount - $refunded, $charge['payment_intent'] ?? null];
    }

    /**
     * Which order, from the signed body: our metadata where the object carries
     * it, else — for a charge, which does not — the PaymentIntent it names,
     * looked up in our own log of whose payment that was.
     *
     * @param  array<string, mixed>  $object
     */
    protected function orderOf(array $object): ?int
    {
        $id = $object['metadata']['order_id'] ?? $object['client_reference_id'] ?? null;

        if ($id !== null && ctype_digit((string) $id)) {
            return (int) $id;
        }

        $intent = $object['payment_intent'] ?? null;

        if (! is_string($intent) || $intent === '') {
            return null;
        }

        $orderId = Transaction::where('gateway', self::key())->where('external_id', $intent)->value('order_id');

        return $orderId ? (int) $orderId : null;
    }

    // ------------------------------------------------------------------ admin

    public function capabilities(): array
    {
        return ['refresh', 'capture', 'refund', 'void', 'cancel'];
    }

    public function act(string $capability, Order $order, array $config, array $params = []): ?PaymentEvent
    {
        $client = $this->client($config);
        // The payment the order screen named: a PaymentIntent once Stripe has
        // spoken about it, still its Checkout session before that.
        $named = (string) ($params['external_id'] ?? '');
        $intent = str_starts_with($named, 'pi_') ? $named : $this->intentId($order, $client, $named);
        $base = (int) ($params['amount'] ?? 0);

        if ($capability === 'cancel') {
            // An unpaid session — there is nothing to release or send back.
            $session = str_starts_with($named, 'cs_') ? $named : $this->sessionId($order);

            if (! $session) {
                return null;
            }

            $answer = $client->post('checkout/sessions/'.$session.'/expire');

            return $answer->successful()
                ? new PaymentEvent(state: PaymentState::EXPIRED, amount: 0, externalId: $session, summary: __('Checkout session expired'), orderId: $order->id)
                : $this->refused($order, $session, $capability, $answer);
        }

        if (! $intent) {
            return null;
        }

        $answer = match ($capability) {
            'capture' => $client->post('payment_intents/'.$intent.'/capture', array_filter([
                'amount_to_capture' => $base > 0 ? $order->chargeMinor($base) : null,
            ])),
            'refund' => $client->post('refunds', array_filter([
                'payment_intent' => $intent,
                'amount' => $base > 0 ? $order->chargeMinor($base) : null,
            ]), 'refund-'.$intent.'-'.$base.'-'.intdiv(time(), 60)),
            'void' => $client->post('payment_intents/'.$intent.'/cancel'),
            default => null,
        };

        if ($answer !== null && ! $answer->successful()) {
            return $this->refused($order, $intent, $capability, $answer);
        }

        // Believe Stripe's own account of the payment, refunds included.
        $body = $client->get('payment_intents/'.$intent, ['expand' => ['latest_charge']])->json() ?? [];

        if (! isset($body['status'])) {
            return null;
        }

        [$state, $amount] = $this->fromIntent($body, $order->id);

        return new PaymentEvent(
            state: $state,
            amount: $amount,
            externalId: $intent,
            summary: $capability.': '.$body['status'],
            raw: $body,
            orderId: $order->id,
        );
    }

    /** Written to the payment log and answered with nothing — the screen points there. */
    protected function refused(Order $order, string $externalId, string $capability, Response $answer): null
    {
        Transaction::create([
            'gateway' => self::key(),
            'order_id' => $order->id,
            'external_id' => $externalId,
            'verified' => true,
            'applied' => false,
            'summary' => $capability.': '.$this->error($answer),
            'raw' => ['response' => $answer->json() ?? $answer->body()],
            'created_at' => now(),
        ]);

        return null;
    }

    /**
     * The PaymentIntent behind this order: the latest one a webhook told us
     * about, else asked of the session (it has one only once the customer
     * paid).
     */
    protected function intentId(Order $order, Client $client, string $session = ''): ?string
    {
        if (str_starts_with($session, 'cs_')) {
            return $client->get('checkout/sessions/'.$session)->json('payment_intent') ?: null;
        }

        $known = Transaction::where('gateway', self::key())
            ->where('order_id', $order->id)
            ->where('external_id', 'like', 'pi\_%')
            ->latest('id')
            ->value('external_id');

        if ($known) {
            return $known;
        }

        $session = $this->sessionId($order);

        return $session ? ($client->get('checkout/sessions/'.$session)->json('payment_intent') ?: null) : null;
    }

    protected function sessionId(Order $order): ?string
    {
        return Transaction::where('gateway', self::key())
            ->where('order_id', $order->id)
            ->where('external_id', 'like', 'cs\_%')
            ->latest('id')
            ->value('external_id');
    }

    protected function error(Response $response): string
    {
        return (string) ($response->json('error.message') ?? __('Stripe answered :status', ['status' => $response->status()]));
    }

    protected function client(array $config): Client
    {
        return new Client((string) ($config['secret_key'] ?? ''));
    }
}
