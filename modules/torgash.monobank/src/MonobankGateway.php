<?php

namespace Torgash\Monobank;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\Gateway;
use Torgash\Payments\Models\Transaction;
use Torgash\Payments\Charge;
use Torgash\Payments\PaymentEvent;
use Torgash\Payments\PaymentFailed;

/**
 * monobank internet acquiring.
 *
 * Written against the published API (monobank.ua/api-docs/acquiring), and
 * deliberately NOT against the add-on for another engine that was read for the design — the
 * add-on's two worst habits are the two things this file does differently:
 *
 * 1. **The webhook is verified by ECDSA over the RAW BODY** (`X-Sign`, base64,
 *    SHA-256, DER), with the merchant's public key from `/api/merchant/pubkey`.
 *    The add-on puts the secret token in the webhook's own query string and
 *    compares `sha1($token)` to it — so the secret rides in access logs, and
 *    anybody who has seen the URL can post "paid".
 * 2. **The order comes out of the signed payload** (`reference`, which is what
 *    we put there when creating the invoice), never out of the callback URL.
 *
 * Currency is sent as an ISO-4217 NUMBER. Until currencies carry that code
 * themselves (§19.7 п.12 — it is a property of a currency, not of this bank),
 * the common ones are mapped here and anything else is REFUSED with a reason:
 * falling back to UAH would bill an order's minor units in a currency nobody
 * chose.
 */
class MonobankGateway implements Gateway
{
    protected const CURRENCIES = ['UAH' => 980, 'USD' => 840, 'EUR' => 978, 'GBP' => 826, 'PLN' => 985];

    public static function key(): string
    {
        return 'monobank';
    }

    public static function label(): string
    {
        return 'monobank Acquiring';
    }

    public function fields(): array
    {
        return [
            'token' => [
                'label' => 'X-Token',
                'type' => 'password',
                'help' => 'From web.monobank.ua. A personal-account test token works against the same endpoints with play money — use one until you have seen a payment go through.',
            ],
            'hold' => [
                'label' => 'Authorize first, take the money later',
                'type' => 'checkbox',
                'help' => 'Two-phase: the card is held and nothing is taken until you press Capture on the order. Use it when you cannot promise the goods are in stock.',
            ],
            'validity_hours' => [
                'label' => 'Invoice is valid for, hours',
                'type' => 'number',
                'help' => 'After this the invoice expires and the customer needs a new one — which the Pay link on their order gives them. Default 24, maximum 720.',
            ],
        ];
    }

    /**
     * What the acquiring can charge in — which is not what the shop prices in.
     * A Ukrainian shop listing in dollars is ordinary; a Ukrainian shop being
     * PAID in dollars needs a currency account, and the bank will simply refuse
     * an invoice in one it does not have.
     */
    public function currencies(): ?array
    {
        return array_keys(self::CURRENCIES);
    }

    public function warnings(array $config): array
    {
        if (empty($config['hold'])) {
            return [];
        }

        // Measured against the live API, which answers "hold payments are
        // allowed only for UAH". Two settings that are each fine on their own
        // and fail together on every order — exactly the kind of thing a form
        // has to say out loud, because the alternative is finding out from a
        // customer who could not pay.
        return [__('Holding the money works only in hryvnia. Orders in any other currency will not be payable with this method while "Authorize first" is on.')];
    }

    // ------------------------------------------------------------------ start

    public function start(Order $order, Charge $charge, array $config): ?string
    {
        $hours = (int) ($config['validity_hours'] ?? 24);
        // The currency the CUSTOMER is being charged in, which is not always
        // the one the shop keeps its books in: a dollar-priced shop taking
        // hryvnia is the ordinary case here, and the bank must be asked for
        // what the customer was quoted.
        $currency = strtoupper($charge->currency);

        // A currency we cannot even name to the bank is refused, not sent as
        // hryvnia. The fallback used to be `?? 980`, which would have charged
        // the order's number of minor units in the WRONG currency — 100.00 CHF
        // billed as 100.00 UAH, with nothing anywhere reporting a problem.
        if (! isset(self::CURRENCIES[$currency])) {
            throw new PaymentFailed(__(
                'Monobank cannot charge in :currency. Give this payment method a currency it takes: :list.',
                ['currency' => $currency ?: '—', 'list' => implode(', ', array_keys(self::CURRENCIES))],
            ));
        }

        // Refused before the round trip, because the bank's own answer to this
        // ("hold payments are allowed only for UAH") names a rule, not the
        // setting that broke it. Measured on the live API — and it is a
        // configuration mistake that only shows up on the first order in
        // another currency, so it has to say which two things disagree.
        if (! empty($config['hold']) && $currency !== 'UAH') {
            throw new PaymentFailed(__(
                'Monobank holds the money only in hryvnia, and this order is charged in :currency. Turn "Hold the money" off on this payment method, or let the customer pay in UAH.',
                ['currency' => $currency ?: '—'],
            ));
        }

        $payload = array_filter([
            'amount' => $charge->amount,
            'ccy' => self::CURRENCIES[$currency],
            'merchantPaymInfo' => array_filter([
                // Our order id, and the only place the callback may learn it.
                'reference' => (string) $order->id,
                'destination' => __('Order :number', ['number' => $order->order_number]),
                'basketOrder' => $this->basket($order, $charge),
            ]),
            'redirectUrl' => route('checkout.confirmation', $order->order_number),
            'webHookUrl' => url('/payment/'.self::key().'/callback'),
            'validity' => max(1, min($hours, 720)) * 3600,
            'paymentType' => ! empty($config['hold']) ? 'hold' : 'debit',
        ]);

        $response = $this->client($config)->post('invoice/create', $payload);
        $body = $response->json() ?? [];

        // A refusal is thrown, not returned: the payments module records it once,
        // in one place, and the customer is told that something went wrong
        // instead of watching a button do nothing. Only the invoice that was
        // actually created is logged here, because only that one has an id
        // worth keeping.
        if (! $response->successful() || empty($body['pageUrl'])) {
            throw new PaymentFailed(
                (string) ($body['errText'] ?? __('the bank answered :status', ['status' => $response->status()]))
            );
        }

        Transaction::create([
            'gateway' => self::key(),
            'order_id' => $order->id,
            'external_id' => $body['invoiceId'] ?? null,
            'state' => PaymentState::PENDING,
            'amount' => $order->chargeNow(),
            'verified' => true,
            'applied' => false,
            'summary' => __('Invoice created'),
            'raw' => ['request' => $payload, 'response' => $body],
            'created_at' => now(),
        ]);

        return $body['pageUrl'];
    }

    /**
     * The lines, but only when they add up to what is being charged.
     *
     * The bank rejects a basket whose sum is not the invoice amount when
     * fiscalisation is on, and our total is goods − discount + delivery + taxes.
     * Delivery is a line we can add honestly; a discount spread across lines is
     * arithmetic with a rounding argument in it, and getting it wrong means
     * refused payments. So when it does not reconcile we send no basket at all,
     * which is valid for every shop that is not fiscalising.
     *
     * Each line is converted into the charge currency the same way the total
     * was, and the reconciliation is done AFTER converting: rounding every line
     * separately can miss the total by a kopeck, and a basket that misses is
     * worse than no basket.
     *
     * @return list<array<string, mixed>>|null
     */
    protected function basket(Order $order, Charge $charge): ?array
    {
        $lines = [];

        foreach ($order->items ?? [] as $item) {
            $lines[] = array_filter([
                'name' => (string) ($item['name'] ?? ''),
                'qty' => (int) ($item['qty'] ?? 1),
                'sum' => $order->chargeMinor((int) ($item['line_total'] ?? 0)),
                'unit' => __('pcs'),
                'code' => (string) ($item['sku'] ?? ''),
            ]);
        }

        if ($order->shipping_cost > 0) {
            $lines[] = [
                'name' => $order->shipping_method_name ?: __('Delivery'),
                'qty' => 1,
                'sum' => $order->chargeMinor($order->shipping_cost),
                'code' => 'delivery',
            ];
        }

        $sum = array_sum(array_column($lines, 'sum'));

        return $lines !== [] && $sum === $charge->amount ? $lines : null;
    }

    // --------------------------------------------------------------- callback

    /**
     * ECDSA over the raw body. The key is cached until it stops working — which
     * is what their documentation asks for, and also the only sane policy: a
     * fetch per callback would make every payment depend on a second request.
     */
    public function verify(Request $request, array $config): bool
    {
        $signature = base64_decode((string) $request->header('X-Sign'), true);

        if (! $signature) {
            return false;
        }

        $body = $request->getContent();

        if ($this->check($body, $signature, $this->publicKey($config))) {
            return true;
        }

        // Merchants can rotate. One forced refresh, then it really is a bad
        // signature.
        return $this->check($body, $signature, $this->publicKey($config, fresh: true));
    }

    protected function check(string $body, string $signature, ?string $pem): bool
    {
        return $pem !== null && openssl_verify($body, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    protected function publicKey(array $config, bool $fresh = false): ?string
    {
        $token = (string) ($config['token'] ?? '');
        $cacheKey = 'monobank.pubkey.'.sha1($token);

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addDay(), function () use ($config) {
            $key = (string) ($this->client($config)->get('pubkey')->json('key') ?? '');

            if ($key === '') {
                return null;
            }

            // They hand it over base64-encoded; decoded it is a PEM block. A
            // gateway that ever returns the PEM directly still works.
            $decoded = base64_decode($key, true);

            return str_contains((string) $decoded, 'BEGIN PUBLIC KEY') ? $decoded : $key;
        });
    }

    public function parse(Request $request, array $config): ?PaymentEvent
    {
        $body = json_decode($request->getContent(), true);

        if (! is_array($body) || ! isset($body['status'])) {
            return null;
        }

        return $this->event($body);
    }

    /**
     * One reading of monobank's invoice shape, used for both the webhook and
     * everything we ask ourselves — they are the same document.
     *
     * The subtle one is a PARTIAL refund: the status stays `success` and
     * `finalAmount` drops below `amount`. Reading only the status would leave an
     * order looking fully paid after half of it went back.
     *
     * @param  array<string, mixed>  $body
     */
    protected function event(array $body): PaymentEvent
    {
        $status = (string) $body['status'];
        $amount = isset($body['amount']) ? (int) $body['amount'] : null;
        $final = isset($body['finalAmount']) ? (int) $body['finalAmount'] : null;

        [$state, $held] = match ($status) {
            'hold' => [PaymentState::AUTHORIZED, $final ?? $amount],
            'success' => match (true) {
                $final === null || $final === $amount => [PaymentState::PAID, $amount],
                $final === 0 => [PaymentState::REFUNDED, 0],
                default => [PaymentState::PARTIALLY_REFUNDED, $final],
            },
            'reversed' => [PaymentState::REFUNDED, 0],
            'failure' => [PaymentState::FAILED, 0],
            'expired' => [PaymentState::EXPIRED, 0],
            default => [PaymentState::PENDING, null],
        };

        return new PaymentEvent(
            state: $state,
            amount: $held,
            externalId: $body['invoiceId'] ?? null,
            // No message id in their payload, so the invoice plus the moment it
            // changed is the identity of the message. A redelivery carries the
            // same pair; a genuinely new state carries a new modifiedDate.
            eventKey: isset($body['invoiceId'])
                ? $body['invoiceId'].':'.($body['modifiedDate'] ?? $status)
                : null,
            summary: trim($status.' '.($body['failureReason'] ?? '')),
            raw: $body,
            orderId: isset($body['reference']) ? (int) $body['reference'] : null,
        );
    }

    // ------------------------------------------------------------------ admin

    public function capabilities(): array
    {
        return ['refresh', 'capture', 'refund', 'cancel'];
    }

    public function act(string $capability, Order $order, array $config, array $params = []): ?PaymentEvent
    {
        // The payment the order screen named (an order with a deposit and its
        // rest has two invoices), else the order's latest.
        $invoiceId = $params['external_id'] ?? $this->invoiceId($order);

        if (! $invoiceId) {
            return null;
        }

        $client = $this->client($config);

        match ($capability) {
            // Amounts arrive from the order screen in the shop's own currency
            // and go to the bank in the one the invoice was raised in — the
            // same conversion the invoice itself went through, or a partial
            // refund would be off by the exchange rate.
            'capture' => $client->post('invoice/finalize', [
                'invoiceId' => $invoiceId,
                'amount' => $order->chargeMinor((int) (($params['amount'] ?? 0) ?: ($params['held'] ?? 0) ?: $order->gatewayPaid() ?: $order->chargeNow())),
            ]),
            'refund' => $client->post('invoice/cancel', array_filter([
                'invoiceId' => $invoiceId,
                'amount' => ($base = (int) ($params['amount'] ?? 0)) ? $order->chargeMinor($base) : null,
                // Unique per refund. The add-on sends 'R'.$order_id every time,
                // so a second partial refund arrives under an id the bank has
                // already seen.
                'extRef' => 'R'.$order->id.'-'.now()->timestamp,
            ])),
            'cancel' => $client->post('invoice/remove', ['invoiceId' => $invoiceId]),
            default => null,
        };

        if ($capability === 'cancel') {
            return new PaymentEvent(
                state: PaymentState::EXPIRED,
                amount: 0,
                externalId: $invoiceId,
                summary: __('Invoice removed'),
                orderId: $order->id,
            );
        }

        // Everything else asks the bank what it now thinks, rather than assuming
        // the action worked: a `processing` cancellation is not a refund yet,
        // and the answer here is what the order should be set from.
        $body = $client->get('invoice/status', ['invoiceId' => $invoiceId])->json() ?? [];

        if (! isset($body['status'])) {
            return null;
        }

        $event = $this->event($body);
        $event->orderId = $order->id;
        // An admin can press Refresh twice, and pressing it must not be a "new"
        // message that the deduplication then swallows for the real callback.
        $event->eventKey = null;

        return $event;
    }

    /** The invoice we made for this order, from our own transaction log. */
    protected function invoiceId(Order $order): ?string
    {
        return Transaction::where('gateway', self::key())
            ->where('order_id', $order->id)
            ->whereNotNull('external_id')
            ->latest('id')
            ->value('external_id');
    }

    protected function client(array $config): Client
    {
        return new Client((string) ($config['token'] ?? ''));
    }
}
