<?php

namespace Torgash\WayForPay;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\AnswersCallback;
use Torgash\Payments\Charge;
use Torgash\Payments\Gateway;
use Torgash\Payments\Models\Transaction;
use Torgash\Payments\PaymentEvent;
use Torgash\Payments\PaymentFailed;

/**
 * WayForPay, through its invoice API (`CREATE_INVOICE`).
 *
 * An invoice rather than the `/pay` form, because the form is a POST that a
 * page of ours would have to submit on the customer's behalf, while an invoice
 * answers with a link — the shape `Gateway::start()` already has, and the one
 * monobank uses.
 *
 * Written from WayForPay's published documentation; there was no add-on to
 * read beside it this time. Three things it asks for that the others did not:
 *
 * 1. **Each kind of message is signed over its OWN list of fields** (HMAC-MD5,
 *    joined by `;`). The lists are written out at each use, never derived from
 *    the body, because the body carries more than is signed.
 * 2. **The callback must be ANSWERED** with a signed `accept` naming the order,
 *    or WayForPay keeps redelivering it for days — hence `AnswersCallback`.
 * 3. **The amount is signed as a string**, so it is prepared once
 *    (`Client::amount()`) and the same value is both sent and signed.
 *
 * No hold: the invoice API does not take `merchantTransactionType`, so this
 * gateway does not pretend to offer one. `orderReference` is `{order id}-{unix
 * time}` — unique per invoice, as WayForPay requires, with the order in front.
 */
class WayForPayGateway implements AnswersCallback, Gateway
{
    protected const CURRENCIES = ['UAH', 'USD', 'EUR'];

    public static function key(): string
    {
        return 'wayforpay';
    }

    public static function label(): string
    {
        return 'WayForPay';
    }

    public function fields(): array
    {
        return [
            'merchant_account' => [
                'label' => 'Merchant account',
                'type' => 'text',
                'help' => 'The merchant login from WayForPay\'s cabinet (Store settings). The test one is test_merch_n1.',
            ],
            'secret_key' => [
                'label' => 'Secret key',
                'type' => 'password',
                'help' => 'Signs every message both ways. It never leaves the server.',
            ],
            'domain' => [
                'label' => 'Shop domain',
                'type' => 'text',
                'help' => 'As registered with WayForPay. Empty means the domain of this site.',
            ],
            'validity_hours' => [
                'label' => 'Invoice is valid for, hours',
                'type' => 'number',
                'help' => 'After this the invoice expires and the customer needs a new one — which the Pay link on their order gives them. Default 24.',
            ],
        ];
    }

    public function currencies(): ?array
    {
        return self::CURRENCIES;
    }

    public function warnings(array $config): array
    {
        return [];
    }

    // ------------------------------------------------------------------ start

    public function start(Order $order, Charge $charge, array $config): ?string
    {
        $currency = strtoupper($charge->currency);

        if (! in_array($currency, self::CURRENCIES, true)) {
            throw new PaymentFailed(__(
                'WayForPay cannot charge in :currency. Give this payment method a currency it takes: :list.',
                ['currency' => $currency ?: '—', 'list' => implode(', ', self::CURRENCIES)],
            ));
        }

        $client = $this->client($config);

        if ($client->account() === '' || ($config['secret_key'] ?? '') === '') {
            throw new PaymentFailed(__('WayForPay credentials are not set on this payment method.'));
        }

        $reference = $order->id.'-'.now()->timestamp;
        $amount = Client::amount($charge->amount);
        $domain = $this->domain($config);
        $date = now()->timestamp;

        // One line — the order itself. WayForPay signs the product list and
        // checks it against the amount; the order's own lines are in the shop's
        // books, the invoice is in the charge currency, and a list that misses
        // the total by a kopeck of conversion rounding is a refused invoice.
        $products = [
            'productName' => [__('Order :number', ['number' => $order->order_number])],
            'productCount' => [1],
            'productPrice' => [$amount],
        ];

        $payload = [
            'transactionType' => 'CREATE_INVOICE',
            'merchantAuthType' => 'SimpleSignature',
            'merchantDomainName' => $domain,
            'merchantSignature' => $client->sign([
                $client->account(), $domain, $reference, $date, $amount, $currency,
                $products['productName'], $products['productCount'], $products['productPrice'],
            ]),
            'orderReference' => $reference,
            'orderDate' => $date,
            'amount' => $amount,
            'currency' => $currency,
            'orderTimeout' => max(1, min((int) ($config['validity_hours'] ?? 24) ?: 24, 720)) * 3600,
            'serviceUrl' => url('/payment/'.self::key().'/callback'),
            'language' => $this->language($order),
        ] + $products;

        $response = $client->call($payload);
        $body = $response->json() ?? [];

        if (! $response->successful() || empty($body['invoiceUrl'])) {
            throw new PaymentFailed(
                (string) ($body['reason'] ?? __('WayForPay answered :status', ['status' => $response->status()]))
            );
        }

        Transaction::create([
            'gateway' => self::key(),
            'order_id' => $order->id,
            'external_id' => $reference,
            'state' => PaymentState::PENDING,
            'amount' => $order->chargeNow(),
            'verified' => true,
            'applied' => false,
            'summary' => __('Invoice created'),
            'raw' => ['request' => $payload, 'response' => $body],
            'created_at' => now(),
        ]);

        return $body['invoiceUrl'];
    }

    protected function domain(array $config): string
    {
        return trim((string) ($config['domain'] ?? '')) ?: (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    protected function language(Order $order): string
    {
        return match ($order->locale ?? app()->getLocale()) {
            'en' => 'EN',
            'ru' => 'RU',
            default => 'UA',
        };
    }

    // --------------------------------------------------------------- callback

    public function verify(Request $request, array $config): bool
    {
        $body = $this->body($request);

        return $body !== null && $this->client($config)->verify(
            $this->callbackFields($body),
            (string) ($body['merchantSignature'] ?? ''),
        );
    }

    public function parse(Request $request, array $config): ?PaymentEvent
    {
        $body = $this->body($request);

        return $body !== null && isset($body['transactionStatus']) ? $this->event($body) : null;
    }

    /**
     * `accept`, signed over `orderReference;accept;time`. Without it WayForPay
     * keeps sending the same callback — which dedup would absorb, but a log
     * filling with repeats for days reads like a fault.
     */
    public function callbackResponse(Request $request, ?PaymentEvent $event, array $config): ?Response
    {
        $reference = (string) ($this->body($request)['orderReference'] ?? '');

        if ($reference === '') {
            return null;
        }

        $time = now()->timestamp;

        return response()->json([
            'orderReference' => $reference,
            'status' => 'accept',
            'time' => $time,
            'signature' => $this->client($config)->sign([$reference, 'accept', $time]),
        ]);
    }

    /**
     * The callback body is JSON — sent, depending on WayForPay's mood, as a
     * raw body under a form content type, so it is read from the raw content
     * first and from the parsed input only when that is not JSON.
     *
     * @return array<string, mixed>|null
     */
    protected function body(Request $request): ?array
    {
        $json = json_decode($request->getContent(), true);

        if (is_array($json)) {
            return $json;
        }

        $input = $request->all();

        return $input === [] ? null : $input;
    }

    /**
     * What a status document is signed over — the callback and CHECK_STATUS
     * share the list.
     *
     * @param  array<string, mixed>  $body
     * @return list<mixed>
     */
    protected function callbackFields(array $body): array
    {
        return array_map(fn (string $field) => $body[$field] ?? '', [
            'merchantAccount', 'orderReference', 'amount', 'currency',
            'authCode', 'cardPan', 'transactionStatus', 'reasonCode',
        ]);
    }

    /**
     * One reading of the status document, for the callback and CHECK_STATUS.
     *
     * `RefundInProcessing` is a refund WayForPay could not make yet (the
     * shop's balance there is too low): the money is still with the shop, so
     * the order stays paid and the log says why. `Refunded`/`Voided` with a
     * `refundAmount` smaller than the payment is PARTIALLY refunded.
     *
     * @param  array<string, mixed>  $body
     */
    protected function event(array $body): PaymentEvent
    {
        $status = (string) $body['transactionStatus'];
        $amount = isset($body['amount']) ? (int) round((float) $body['amount'] * 100) : null;
        $refunded = isset($body['refundAmount']) ? (int) round((float) $body['refundAmount'] * 100) : null;

        [$state, $held] = match (strtolower($status)) {
            'approved', 'refundinprocessing' => [PaymentState::PAID, $amount],
            'waitingauthcomplete' => [PaymentState::AUTHORIZED, $amount],
            'refunded', 'voided' => $refunded && $amount !== null && $refunded < $amount
                ? [PaymentState::PARTIALLY_REFUNDED, $amount - $refunded]
                : [PaymentState::REFUNDED, 0],
            'declined' => [PaymentState::FAILED, 0],
            'expired' => [PaymentState::EXPIRED, 0],
            default => [PaymentState::PENDING, null],
        };

        $reference = isset($body['orderReference']) ? (string) $body['orderReference'] : null;

        return new PaymentEvent(
            state: $state,
            amount: $held,
            externalId: $reference,
            eventKey: $reference !== null
                ? $reference.':'.$status.':'.($body['processingDate'] ?? '').':'.($body['refundAmount'] ?? '')
                : null,
            summary: trim($status.' '.($body['reason'] ?? '')),
            raw: $body,
            orderId: $reference !== null && preg_match('/^(\d+)-/', $reference, $m) ? (int) $m[1] : null,
        );
    }

    // ------------------------------------------------------------------ admin

    /** No hold to capture, and an unpaid invoice simply expires. */
    public function capabilities(): array
    {
        return ['refresh', 'refund'];
    }

    public function act(string $capability, Order $order, array $config, array $params = []): ?PaymentEvent
    {
        // The payment the order screen named, else the order's own latest.
        $reference = $params['external_id'] ?? $this->reference($order);

        if (! $reference) {
            return null;
        }

        $client = $this->client($config);

        if ($capability === 'refund') {
            $base = (int) ($params['amount'] ?? 0) ?: (int) ($params['held'] ?? 0) ?: $order->gatewayPaid();
            $amount = Client::amount($order->chargeMinor($base));
            $currency = $order->paymentCurrency();

            $answer = $client->call([
                'transactionType' => 'REFUND',
                'orderReference' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'comment' => __('Refund for order :number', ['number' => $order->order_number]),
                'merchantSignature' => $client->sign([$client->account(), $reference, $amount, $currency]),
            ]);

            $status = strtolower((string) $answer->json('transactionStatus'));

            if (! $answer->successful() || ! in_array($status, ['refunded', 'voided', 'refundinprocessing'], true)) {
                // Written down and answered with nothing — the order screen
                // points at the payment log, where the reason is.
                Transaction::create([
                    'gateway' => self::key(),
                    'order_id' => $order->id,
                    'external_id' => $reference,
                    'verified' => true,
                    'applied' => false,
                    'summary' => trim('refund: '.($answer->json('reason') ?? __('WayForPay answered :status', ['status' => $answer->status()]))),
                    'raw' => ['response' => $answer->json() ?? $answer->body()],
                    'created_at' => now(),
                ]);

                return null;
            }
        }

        // Ask what WayForPay now thinks, and believe that.
        $body = $client->call([
            'transactionType' => 'CHECK_STATUS',
            'orderReference' => $reference,
            'merchantSignature' => $client->sign([$client->account(), $reference]),
        ])->json() ?? [];

        if (! isset($body['transactionStatus'])) {
            return null;
        }

        $event = $this->event($body);
        $event->orderId = $order->id;
        $event->eventKey = null;

        // A partial refund answered without `refundAmount` is still partial:
        // we know what we asked to give back.
        if ($capability === 'refund' && $event->state === PaymentState::REFUNDED
            && ! isset($body['refundAmount']) && ($base = (int) ($params['amount'] ?? 0)) > 0
            && $base < ($held = (int) ($params['held'] ?? $order->gatewayPaid()))) {
            // Measured against THIS payment: what it held, not the order.
            $event->state = PaymentState::PARTIALLY_REFUNDED;
            $event->amount = $order->chargeMinor($held - $base);
        }

        return $event;
    }

    /** The invoice WayForPay reported on last, else the last one created. */
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
        return new Client((string) ($config['merchant_account'] ?? ''), (string) ($config['secret_key'] ?? ''));
    }
}
