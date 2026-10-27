<?php

namespace Tests\Fixtures\Payments;

use Illuminate\Http\Request;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\Gateway;
use Torgash\Payments\PaymentEvent;

/**
 * A gateway with the shape of a real one and none of the network.
 *
 * Modelled on monobank's acquiring specifically: it makes an invoice and hands
 * back a page to redirect to, it signs the RAW BODY (here with a plain HMAC
 * rather than ECDSA — the point under test is that verification reads the raw
 * bytes, not which algorithm), it names the order INSIDE the signed payload,
 * and it can capture a hold, refund and be re-asked.
 */
class FakeGateway implements Gateway
{
    /** @var list<array{order: int, amount: int, currency: string, config: array}> */
    public static array $started = [];

    public static ?string $pageUrl = 'https://pay.example.test/invoice/1';

    /** Set to make start() refuse the way a real gateway refuses: with a reason. */
    public static ?string $failWith = null;

    /** What the acquiring takes; null is "anything", as a fake normally is. */
    public static ?array $currencies = null;

    /** @var list<string> */
    public static array $warnings = [];

    /** @var list<string> capabilities a test turns on, `void` */
    public static array $extraCapabilities = [];

    /** @var list<array{capability: string, params: array}> every act() call, in order */
    public static array $acted = [];

    public static function reset(): void
    {
        self::$started = [];
        self::$pageUrl = 'https://pay.example.test/invoice/1';
        self::$failWith = null;
        self::$currencies = null;
        self::$warnings = [];
        self::$acted = [];
        self::$extraCapabilities = [];
    }

    public static function key(): string
    {
        return 'fake';
    }

    public static function label(): string
    {
        return 'Fake gateway';
    }

    public function fields(): array
    {
        return [
            'token' => ['label' => 'Token', 'type' => 'password'],
            'hold' => ['label' => 'Authorize first', 'type' => 'checkbox'],
        ];
    }

    /** Anything: the fake stands in for a gateway, not for a bank's currency list. */
    public function currencies(): ?array
    {
        return self::$currencies;
    }

    public function warnings(array $config): array
    {
        return self::$warnings;
    }

    public function start(Order $order, \Torgash\Payments\Charge $charge, array $config): ?string
    {
        self::$started[] = [
            'order' => $order->id,
            'amount' => $charge->amount,
            'currency' => $charge->currency,
            'config' => $config,
        ];

        if (self::$failWith !== null) {
            throw new \Torgash\Payments\PaymentFailed(self::$failWith);
        }

        return self::$pageUrl;
    }

    public function verify(Request $request, array $config): bool
    {
        // Raw body, deliberately — a signature over a re-encoded copy of the
        // decoded JSON is the bug this shape exists to make impossible.
        $expected = hash_hmac('sha256', $request->getContent(), (string) ($config['token'] ?? ''));

        return hash_equals($expected, (string) $request->header('X-Signature'));
    }

    public function parse(Request $request, array $config): ?PaymentEvent
    {
        $body = json_decode($request->getContent(), true);

        if (! is_array($body) || ! isset($body['status'])) {
            return null;
        }

        $state = match ($body['status']) {
            'created', 'processing' => PaymentState::PENDING,
            'hold' => PaymentState::AUTHORIZED,
            'success' => PaymentState::PAID,
            'reversed' => PaymentState::REFUNDED,
            'failure' => PaymentState::FAILED,
            'expired' => PaymentState::EXPIRED,
            default => PaymentState::PENDING,
        };

        return new PaymentEvent(
            state: $state,
            amount: isset($body['amount']) ? (int) $body['amount'] : null,
            externalId: $body['invoiceId'] ?? null,
            eventKey: $body['messageId'] ?? null,
            summary: 'gateway says '.$body['status'],
            raw: $body,
            // From the signed payload, never from the URL.
            orderId: isset($body['reference']) ? (int) $body['reference'] : null,
        );
    }

    public function capabilities(): array
    {
        return ['refresh', 'capture', 'refund', ...self::$extraCapabilities];
    }

    public function act(string $capability, Order $order, array $config, array $params = []): ?PaymentEvent
    {
        self::$acted[] = ['capability' => $capability, 'params' => $params];

        // Asked about one payment, it answers about that one, the way a real
        // acquirer does: part of what it holds goes back, a release is money
        // back at zero.
        $id = $params['external_id'] ?? 'inv-1';
        $held = $params['held'] ?? null;
        $amount = $params['amount'] ?? null;

        return match ($capability) {
            // An unpaid invoice was made for what was left to pay then.
            'refresh' => new PaymentEvent(PaymentState::PAID, $held ?: (isset($params['external_id']) ? $order->chargeNow() : $order->total), $id, null, 'refreshed'),
            'capture' => new PaymentEvent(PaymentState::PAID, $held ?: $order->total, $id, null, 'captured'),
            'refund' => $held !== null && $amount !== null && $amount < $held
                ? new PaymentEvent(PaymentState::PARTIALLY_REFUNDED, $held - $amount, $id, null, 'refunded part')
                : new PaymentEvent(PaymentState::REFUNDED, 0, $id, null, 'refunded'),
            'void' => new PaymentEvent(PaymentState::REFUNDED, 0, $id, null, 'released'),
            default => null,
        };
    }
}
