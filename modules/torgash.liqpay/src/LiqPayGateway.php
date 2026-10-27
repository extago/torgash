<?php

namespace Torgash\LiqPay;

use Illuminate\Http\Request;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\Charge;
use Torgash\Payments\Gateway;
use Torgash\Payments\Models\Transaction;
use Torgash\Payments\PaymentEvent;
use Torgash\Payments\PaymentFailed;

/**
 * LiqPay (PrivatBank), API v3.
 *
 * Written against the published API and read beside an existing add-on for
 * another engine for the shape of a real integration — and, as with monobank,
 * the add-on's habits are the list of things this file does differently:
 *
 * 1. **The order comes out of the SIGNED data**, never the callback URL. The
 *    add-on puts `order_id` in `server_url`'s query string and trusts it, so a
 *    validly signed callback about one order can be replayed at another.
 *    Here the callback URL names only the gateway.
 * 2. **The signature is compared in constant time** (`hash_equals`), not `==`.
 * 3. **A currency LiqPay cannot take is refused with a reason.** The add-on
 *    quietly charges the first currency on the shop's list that it can take,
 *    at whatever rate that list says — a price the customer was never shown.
 *
 * LiqPay's `order_id` must be unique per ATTEMPT (paying again after a failed
 * or expired attempt needs a new one), so ours is `{order id}-{unix time}`:
 * the prefix is how a callback finds the order, the suffix is what makes a
 * second attempt legal. It is kept as the transaction's external id, which is
 * how the order screen's buttons know what to ask about.
 */
class LiqPayGateway implements Gateway
{
    /** What LiqPay charges in; everything else is refused before the redirect. */
    protected const CURRENCIES = ['UAH', 'USD', 'EUR'];

    public static function key(): string
    {
        return 'liqpay';
    }

    public static function label(): string
    {
        return 'LiqPay';
    }

    public function fields(): array
    {
        return [
            'public_key' => [
                'label' => 'Public key',
                'type' => 'text',
                'help' => 'From liqpay.ua → your shop → API. A key starting with "sandbox_" works only with the test switch on.',
            ],
            'private_key' => [
                'label' => 'Private key',
                'type' => 'password',
                'help' => 'Signs every message both ways. It never leaves the server: not in a URL, not in the page.',
            ],
            'hold' => [
                'label' => 'Authorize first, take the money later',
                'type' => 'checkbox',
                'help' => 'Two-phase: the card is held and nothing is taken until you press Capture on the order.',
            ],
            'validity_hours' => [
                'label' => 'Payment page is valid for, hours',
                'type' => 'number',
                'help' => 'After this LiqPay refuses the payment and the customer needs a new page — which the Pay link on their order gives them. Empty means LiqPay\'s own default.',
            ],
        ];
    }

    public function currencies(): ?array
    {
        return self::CURRENCIES;
    }

    /**
     * The trap LiqPay sets: a sandbox key and a live one look alike, and each
     * works only with its own `sandbox` flag. A sandbox key with the switch off
     * refuses every payment; a live key with it on takes nothing and reports
     * success — the worse of the two, since the shop ships.
     */
    public function warnings(array $config): array
    {
        $isSandboxKey = str_starts_with((string) ($config['public_key'] ?? ''), 'sandbox_');
        $testMode = ! empty($config['test_mode']);

        return match (true) {
            $isSandboxKey && ! $testMode => [__('The public key is a sandbox key, and test mode is off. Every payment will be refused. Turn test mode on, or paste the live keys.')],
            ! $isSandboxKey && $testMode && ! empty($config['public_key']) => [__('Test mode is on: LiqPay takes no real money and still reports the payment as successful. Turn it off before taking real orders.')],
            default => [],
        };
    }

    // ------------------------------------------------------------------ start

    public function start(Order $order, Charge $charge, array $config): ?string
    {
        $currency = strtoupper($charge->currency);

        if (! in_array($currency, self::CURRENCIES, true)) {
            throw new PaymentFailed(__(
                'LiqPay cannot charge in :currency. Give this payment method a currency it takes: :list.',
                ['currency' => $currency ?: '—', 'list' => implode(', ', self::CURRENCIES)],
            ));
        }

        if (($config['public_key'] ?? '') === '' || ($config['private_key'] ?? '') === '') {
            throw new PaymentFailed(__('LiqPay keys are not set on this payment method.'));
        }

        $reference = $order->id.'-'.now()->timestamp;
        $hours = (int) ($config['validity_hours'] ?? 0);

        $params = array_filter([
            'action' => ! empty($config['hold']) ? 'hold' : 'pay',
            // Major units: LiqPay's API takes a decimal amount, unlike
            // monobank's kopecks. Every currency it takes has two decimals.
            'amount' => round($charge->amount / 100, 2),
            'currency' => $currency,
            'description' => __('Order :number', ['number' => $order->order_number]),
            'order_id' => $reference,
            'result_url' => route('checkout.confirmation', $order->order_number),
            'server_url' => url('/payment/'.self::key().'/callback'),
            'language' => $this->language($order),
            // In UTC, which is what their documentation asks for.
            'expired_date' => $hours > 0 ? now()->utc()->addHours(min($hours, 720))->format('Y-m-d H:i:s') : null,
            'sandbox' => ! empty($config['test_mode']) ? 1 : null,
        ], fn ($value) => $value !== null);

        $url = $this->client($config)->checkoutUrl($params);

        // The page is signed locally, so nothing answered yet — this row is
        // what the order screen's buttons will ask LiqPay about.
        Transaction::create([
            'gateway' => self::key(),
            'order_id' => $order->id,
            'external_id' => $reference,
            'state' => PaymentState::PENDING,
            'amount' => $order->chargeNow(),
            'verified' => true,
            'applied' => false,
            'summary' => __('Payment page prepared'),
            'raw' => ['request' => $params],
            'created_at' => now(),
        ]);

        return $url;
    }

    /** LiqPay speaks Ukrainian and English; anyone else gets Ukrainian. */
    protected function language(Order $order): string
    {
        return ($order->locale ?? app()->getLocale()) === 'en' ? 'en' : 'uk';
    }

    // --------------------------------------------------------------- callback

    /**
     * The callback is a FORM post of `data` + `signature`, and the signature is
     * over `data` exactly as sent — so it is read as the string that arrived
     * and never re-encoded.
     */
    public function verify(Request $request, array $config): bool
    {
        return $this->client($config)->verify(
            (string) $request->input('data', ''),
            (string) $request->input('signature', ''),
        );
    }

    public function parse(Request $request, array $config): ?PaymentEvent
    {
        $body = Client::decode((string) $request->input('data', ''));

        if ($body === null || ! isset($body['status'])) {
            return null;
        }

        return $this->event($body);
    }

    /**
     * One reading of LiqPay's payment document, for the callback and for
     * `status` alike — they are the same object.
     *
     * `wait_accept` is PAID: the customer's money has been taken, LiqPay is only
     * waiting to finish checking the SHOP, and treating it as unpaid would have
     * a manager chasing a customer who has already paid. `sandbox` is what a
     * successful test payment is called.
     *
     * A refund reads its amount where LiqPay gives one (`refund_amount`): a
     * `reversed` payment with money left on it is partially refunded, not
     * refunded — the same trap monobank's unchanged `success` sets.
     *
     * @param  array<string, mixed>  $body
     */
    protected function event(array $body): PaymentEvent
    {
        $status = (string) $body['status'];
        $amount = isset($body['amount']) ? (int) round((float) $body['amount'] * 100) : null;
        $refunded = isset($body['refund_amount']) ? (int) round((float) $body['refund_amount'] * 100) : null;

        [$state, $held] = match ($status) {
            'success', 'sandbox', 'wait_accept' => [PaymentState::PAID, $amount],
            'hold_wait' => [PaymentState::AUTHORIZED, $amount],
            'reversed' => $refunded !== null && $amount !== null && $refunded < $amount
                ? [PaymentState::PARTIALLY_REFUNDED, $amount - $refunded]
                : [PaymentState::REFUNDED, 0],
            'failure', 'error' => [PaymentState::FAILED, 0],
            default => [PaymentState::PENDING, null],
        };

        $reference = isset($body['order_id']) ? (string) $body['order_id'] : null;

        return new PaymentEvent(
            state: $state,
            amount: $held,
            externalId: $reference,
            // No message id of their own; the payment plus its status plus the
            // amount refunded so far is the identity of one message. A second
            // partial refund differs in the last part and is new news.
            eventKey: isset($body['payment_id'])
                ? $body['payment_id'].':'.$status.':'.($body['refund_amount'] ?? '')
                : null,
            summary: trim($status.' '.($body['err_description'] ?? '')),
            raw: $body,
            orderId: $reference !== null && preg_match('/^(\d+)-/', $reference, $m) ? (int) $m[1] : null,
        );
    }

    // ------------------------------------------------------------------ admin

    /**
     * No `cancel`: a checkout page is not an invoice, there is nothing to
     * remove, and it expires by itself. No `void` either: LiqPay lets a hold go
     * through the same `refund` call that returns a payment.
     */
    public function capabilities(): array
    {
        return ['refresh', 'capture', 'refund'];
    }

    public function act(string $capability, Order $order, array $config, array $params = []): ?PaymentEvent
    {
        // The payment the order screen named, else the order's own latest.
        $reference = $params['external_id'] ?? $this->reference($order);

        if (! $reference) {
            return null;
        }

        $client = $this->client($config);

        // Amounts arrive from the order screen in the shop's own currency and
        // go to LiqPay in the one the payment was made in — the conversion the
        // payment itself went through.
        $major = fn (int $base) => round($order->chargeMinor($base) / 100, 2);

        $answer = match ($capability) {
            'capture' => $client->request(array_filter([
                'action' => 'hold_completion',
                'order_id' => $reference,
                'amount' => ($base = (int) ($params['amount'] ?? 0)) ? $major($base) : null,
            ])),
            'refund' => $client->request(array_filter([
                'action' => 'refund',
                'order_id' => $reference,
                'amount' => ($base = (int) ($params['amount'] ?? 0)) ? $major($base) : null,
            ])),
            default => null,
        };

        // A refusal is written to the payment log and answered with nothing —
        // the order screen then says "see the payment log", where the reason
        // is. Asking for the status after a refused refund would report the
        // payment as it was, which reads like the refund went through.
        if ($answer !== null && ($answer->json('result') === 'error' || ! $answer->successful())) {
            Transaction::create([
                'gateway' => self::key(),
                'order_id' => $order->id,
                'external_id' => $reference,
                'verified' => true,
                'applied' => false,
                'summary' => trim($capability.': '.($answer->json('err_description') ?? __('LiqPay answered :status', ['status' => $answer->status()]))),
                'raw' => ['response' => $answer->json() ?? $answer->body()],
                'created_at' => now(),
            ]);

            return null;
        }

        // Then ask what LiqPay now thinks, rather than assuming the action
        // worked — the answer is what the order is set from.
        $body = $client->request(['action' => 'status', 'order_id' => $reference])->json() ?? [];

        if (! isset($body['status']) || ($body['result'] ?? null) === 'error') {
            return null;
        }

        $event = $this->event($body);
        $event->orderId = $order->id;

        // A partial refund LiqPay reports as `reversed` without saying how
        // much went back is still partial: we know what we asked for, and
        // taking "reversed" at its word would mark half an order refunded in
        // full.
        if ($capability === 'refund' && $event->state === PaymentState::REFUNDED
            && ! isset($body['refund_amount']) && ($base = (int) ($params['amount'] ?? 0)) > 0
            && $base < ($held = (int) ($params['held'] ?? $order->gatewayPaid()))) {
            // Measured against THIS payment: what it held, not the order.
            $event->state = PaymentState::PARTIALLY_REFUNDED;
            $event->amount = $order->chargeMinor($held - $base);
        }

        // Pressing Refresh twice must not become a "new" message that the
        // deduplication then swallows for the real callback.
        $event->eventKey = null;

        return $event;
    }

    /**
     * The attempt that got somewhere: the latest one LiqPay itself reported
     * on, else the latest page we prepared. A customer who opened the page
     * twice and paid the first has two references, and asking about the
     * second would report "not paid".
     */
    protected function reference(Order $order): ?string
    {
        $log = Transaction::where('gateway', self::key())
            ->where('order_id', $order->id)
            ->whereNotNull('external_id')
            ->latest('id');

        return (clone $log)->where('state', '!=', PaymentState::PENDING)->value('external_id')
            ?? $log->value('external_id');
    }

    protected function client(array $config): Client
    {
        return new Client((string) ($config['public_key'] ?? ''), (string) ($config['private_key'] ?? ''));
    }
}
