<?php

namespace Tests\Feature\Payments;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Monobank\MonobankGateway;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Charge;
use Torgash\Payments\Models\Transaction;
use Torgash\Payments\PaymentFailed;

/**
 * monobank acquiring, against the published contract.
 *
 * The signature test is the one that matters and the reason the module exists
 * in this shape: a real ECDSA key pair is generated here, a body is signed with
 * it exactly as the bank does (SHA-256, DER, base64 in `X-Sign`), and the
 * callback is posted through the actual route. Tampering with one byte of the
 * body must stop the order being paid.
 */
class MonobankGatewayTest extends TestCase
{
    protected Product $product;

    protected PaymentMethod $method;

    /** @var \OpenSSLAsymmetricKey */
    protected $key;

    protected string $pem;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();

        $this->key = $this->newKey();
        $this->pem = openssl_pkey_get_details($this->key)['key'];

        hook_listen('payments.gateways', fn (array $g) => $g + ['monobank' => MonobankGateway::class]);

        $this->product = Product::create([
            'sku' => 'MONO-1',
            'name' => ['en' => 'Monobank widget'],
            'slug' => 'monobank-widget',
            'price' => 50_00,
            'stock' => 10,
            'status' => 'active',
        ]);

        $this->method = PaymentMethod::where('enabled', true)->orderBy('id')->firstOrFail();

        Binding::create([
            'payment_method_id' => $this->method->id,
            'gateway' => 'monobank',
            'config' => ['token' => 'test-token', 'validity_hours' => 24],
            'test_mode' => true,
        ]);
    }

    // ----------------------------------------------------------- the invoice

    public function test_starting_a_payment_asks_for_an_invoice_and_keeps_its_id(): void
    {
        Http::fake([
            'api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'inv-1', 'pageUrl' => 'https://pay.mbnk.biz/inv-1',
            ]),
        ]);

        $order = $this->order();
        $url = app(MonobankGateway::class)->start($o = $order, Charge::for($o), ['token' => 'test-token']);

        $this->assertSame('https://pay.mbnk.biz/inv-1', $url);

        Http::assertSent(function ($request) use ($order) {
            $body = $request->data();

            return $request->hasHeader('X-Token', 'test-token')
                // Minor units and an ISO NUMBER, both as the API asks.
                && $body['amount'] === 100_00
                && $body['ccy'] === 980
                // Our order id travels inside the invoice, which is the only
                // place the callback is allowed to learn it from.
                && $body['merchantPaymInfo']['reference'] === (string) $order->id
                && str_ends_with($body['webHookUrl'], '/payment/monobank/callback')
                // ...and the token is NOT in that URL, which is the whole point.
                && ! str_contains($body['webHookUrl'], 'test-token')
                && $body['validity'] === 24 * 3600
                && $body['paymentType'] === 'debit';
        });

        $transaction = Transaction::latest('id')->firstOrFail();
        $this->assertSame('inv-1', $transaction->external_id);
    }

    public function test_a_hold_binding_asks_for_a_two_phase_payment(): void
    {
        Http::fake(['*' => Http::response(['invoiceId' => 'inv-2', 'pageUrl' => 'https://pay.mbnk.biz/inv-2'])]);

        app(MonobankGateway::class)->start($o = $this->order(), Charge::for($o), ['token' => 't', 'hold' => true]);

        Http::assertSent(fn ($request) => $request->data()['paymentType'] === 'hold');
    }

    /**
     * Measured against the live API: the bank answers "hold payments are
     * allowed only for UAH", and a shop with hold on and prices in dollars
     * therefore had a Pay button that did nothing on every order it ever took
     * (§48 of the log). Refused here instead, before the round trip, because
     * the bank's sentence names the rule while the shop needs to be told which
     * two of its own settings disagree.
     */
    public function test_a_hold_in_another_currency_is_refused_with_a_reason(): void
    {
        Http::fake(['*' => Http::response(['invoiceId' => 'inv-x', 'pageUrl' => 'https://x'])]);

        try {
            $order = $this->order(['currency_code' => 'USD']);
            app(MonobankGateway::class)->start($order, Charge::for($order), ['token' => 't', 'hold' => true]);
            $this->fail('a hold in dollars should be refused');
        } catch (PaymentFailed $e) {
            $this->assertStringContainsString('hryvnia', $e->getMessage());
            $this->assertStringContainsString('USD', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /** A refusal from the bank is a reason, not a null — the null meant "no online payment". */
    public function test_a_refused_invoice_throws_instead_of_returning_nothing(): void
    {
        Http::fake(['*' => Http::response(['errText' => 'Invalid amount'], 400)]);

        try {
            app(MonobankGateway::class)->start($o = $this->order(), Charge::for($o), ['token' => 't']);
            $this->fail('a refused invoice should be reported');
        } catch (PaymentFailed $e) {
            $this->assertSame('Invalid amount', $e->getMessage());
        }
    }

    public function test_a_basket_that_does_not_add_up_to_the_charge_is_not_sent(): void
    {
        Http::fake(['*' => Http::response(['invoiceId' => 'inv-3', 'pageUrl' => 'https://x'])]);

        // Goods 100.00, delivery 7.50, but a 10.00 discount: the lines cannot
        // reconcile without spreading it, so no basket goes at all rather than
        // one the bank would refuse.
        $order = $this->order(['shipping_cost' => 7_50, 'discount_amount' => 10_00, 'total' => 97_50]);

        app(MonobankGateway::class)->start($o = $order, Charge::for($o), ['token' => 't']);

        Http::assertSent(fn ($request) => ! isset($request->data()['merchantPaymInfo']['basketOrder']));
    }

    public function test_a_basket_that_reconciles_is_sent(): void
    {
        Http::fake(['*' => Http::response(['invoiceId' => 'inv-4', 'pageUrl' => 'https://x'])]);

        $order = $this->order(['shipping_cost' => 7_50, 'total' => 107_50]);

        app(MonobankGateway::class)->start($o = $order, Charge::for($o), ['token' => 't']);

        Http::assertSent(function ($request) {
            $basket = $request->data()['merchantPaymInfo']['basketOrder'];

            return count($basket) === 2
                && $basket[0]['sum'] === 100_00
                && $basket[1]['sum'] === 7_50;
        });
    }

    // --------------------------------------------------------- the signature

    public function test_a_properly_signed_webhook_pays_the_order(): void
    {
        $order = $this->order();
        $body = $this->body($order, ['status' => 'success', 'amount' => 100_00]);

        $this->fakePubkey();
        $this->postCallback($body, $this->sign($body))->assertOk();

        $order->refresh();

        $this->assertSame(PaymentState::PAID, $order->payment_state);
        $this->assertSame(100_00, $order->paid_total);
    }

    public function test_one_changed_byte_stops_the_payment(): void
    {
        $order = $this->order();
        $body = $this->body($order, ['status' => 'success', 'amount' => 100_00]);
        $signature = $this->sign($body);

        $this->fakePubkey();

        $tampered = str_replace('"amount":10000', '"amount":90000', $body);
        $this->assertNotSame($body, $tampered, 'the test must actually change the body');

        // The signature is over the RAW BODY. This is the case the reference
        // add-on cannot catch at all: it compares a token from the query string.
        $this->postCallback($tampered, $signature)->assertOk();

        $this->assertSame(PaymentState::PENDING, $order->fresh()->payment_state);
        $this->assertFalse(Transaction::latest('id')->firstOrFail()->verified);
    }

    public function test_a_rotated_key_is_fetched_once_more_before_giving_up(): void
    {
        $order = $this->order();

        // The shop has a stale key cached; the bank now signs with another.
        Cache::put('monobank.pubkey.'.sha1('test-token'), $this->staleKey(), now()->addDay());
        $this->fakePubkey();

        $body = $this->body($order, ['status' => 'success', 'amount' => 100_00]);
        $this->postCallback($body, $this->sign($body))->assertOk();

        $this->assertSame(PaymentState::PAID, $order->fresh()->payment_state);
    }

    // ------------------------------------------------------------- the states

    public function test_a_partial_refund_is_read_from_the_final_amount(): void
    {
        $order = $this->order();
        $this->fakePubkey();

        $paid = $this->body($order, ['status' => 'success', 'amount' => 100_00, 'modifiedDate' => '2026-09-03T10:00:00Z']);
        $this->postCallback($paid, $this->sign($paid))->assertOk();

        // Their status STAYS success on a partial refund; only finalAmount
        // drops. Reading the status alone would leave this looking fully paid.
        $partial = $this->body($order, [
            'status' => 'success', 'amount' => 100_00, 'finalAmount' => 40_00,
            'modifiedDate' => '2026-09-03T11:00:00Z',
        ]);
        $this->postCallback($partial, $this->sign($partial))->assertOk();

        $order->refresh();

        $this->assertSame(PaymentState::PARTIALLY_REFUNDED, $order->payment_state);
        $this->assertSame(40_00, $order->paid_total);
    }

    public function test_a_hold_is_authorized_not_paid(): void
    {
        $order = $this->order();
        $this->fakePubkey();

        $body = $this->body($order, ['status' => 'hold', 'amount' => 100_00]);
        $this->postCallback($body, $this->sign($body))->assertOk();

        // The money is ring-fenced, not taken — a manager deciding whether to
        // ship must see the difference.
        $this->assertSame(PaymentState::AUTHORIZED, $order->fresh()->payment_state);
    }

    public function test_the_same_invoice_state_delivered_twice_is_one_payment(): void
    {
        $order = $this->order();
        $this->fakePubkey();

        $body = $this->body($order, ['status' => 'success', 'amount' => 100_00, 'modifiedDate' => '2026-09-03T10:00:00Z']);

        $this->postCallback($body, $this->sign($body))->assertOk();
        $this->postCallback($body, $this->sign($body))->assertOk();

        $this->assertSame(1, Transaction::where('applied', true)->count());
    }

    // ------------------------------------------------------------- the admin

    public function test_capturing_a_hold_finalizes_and_then_believes_the_bank(): void
    {
        $order = $this->order(['payment_state' => PaymentState::AUTHORIZED, 'paid_total' => 100_00]);
        Transaction::create([
            'gateway' => 'monobank', 'order_id' => $order->id, 'external_id' => 'inv-9',
            'state' => PaymentState::AUTHORIZED, 'verified' => true, 'applied' => false, 'created_at' => now(),
        ]);

        Http::fake([
            '*invoice/finalize' => Http::response(['status' => 'success']),
            '*invoice/status*' => Http::response(['invoiceId' => 'inv-9', 'status' => 'success', 'amount' => 100_00]),
        ]);

        $event = app(MonobankGateway::class)->act('capture', $order, ['token' => 't'], ['amount' => 100_00]);

        $this->assertSame(PaymentState::PAID, $event->state);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'invoice/finalize')
            ? $request->data()['amount'] === 100_00
            : true);
    }

    public function test_each_refund_carries_its_own_reference(): void
    {
        $order = $this->order(['payment_state' => PaymentState::PAID, 'paid_total' => 100_00]);
        Transaction::create([
            'gateway' => 'monobank', 'order_id' => $order->id, 'external_id' => 'inv-10',
            'state' => PaymentState::PAID, 'verified' => true, 'applied' => false, 'created_at' => now(),
        ]);

        Http::fake([
            '*invoice/cancel' => Http::response(['status' => 'success']),
            '*invoice/status*' => Http::response(['invoiceId' => 'inv-10', 'status' => 'success', 'amount' => 100_00, 'finalAmount' => 60_00]),
        ]);

        $event = app(MonobankGateway::class)->act('refund', $order, ['token' => 't'], ['amount' => 40_00]);

        // A second partial refund under the same reference is one the bank has
        // already seen — the add-on sends 'R'.$order_id every single time.
        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'invoice/cancel')
            || str_starts_with($request->data()['extRef'], 'R'.$order->id.'-'));

        $this->assertSame(PaymentState::PARTIALLY_REFUNDED, $event->state);
        $this->assertSame(60_00, $event->amount);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A throwaway key pair, standing in for the bank's.
     *
     * Windows PHP cannot GENERATE a key without an openssl.cnf, and the one it
     * ships with sits beside the binary — `PHP_BINDIR` is a build-time constant
     * (`C:\php` here) and useless, so the path comes from `PHP_BINARY`.
     * Verification needs no config at all, which is why this is a test-only
     * concern and the gateway itself has none of it.
     */
    protected function newKey()
    {
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $config = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';

        $key = openssl_pkey_new($options)
            ?: (is_file($config) ? openssl_pkey_new($options + ['config' => $config]) : false);

        $this->assertNotFalse($key, 'this PHP cannot generate an EC key, so the signature cannot be tested');

        return $key;
    }

    protected function staleKey(): string
    {
        return openssl_pkey_get_details($this->newKey())['key'];
    }

    protected function fakePubkey(): void
    {
        Http::fake([
            '*merchant/pubkey' => Http::response(['key' => base64_encode($this->pem)]),
        ]);
    }

    protected function sign(string $body): string
    {
        openssl_sign($body, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /** @param  array<string, mixed>  $fields */
    protected function body(Order $order, array $fields): string
    {
        return json_encode($fields + [
            'invoiceId' => 'inv-1',
            'reference' => (string) $order->id,
            'ccy' => 980,
        ]);
    }

    protected function postCallback(string $body, string $signature)
    {
        return $this->call(
            'POST',
            'http://localhost/payment/monobank/callback',
            [], [], [],
            ['HTTP_X-Sign' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $body,
        );
    }

    /** @param  array<string, mixed>  $overrides */
    protected function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TG-MB'.random_int(1000, 9999),
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'currency_code' => 'UAH',
            'subtotal' => 100_00,
            'shipping_cost' => 0,
            'total' => 100_00,
            'status' => 'new',
            'payment_method_name' => $this->method->t('name'),
            'stock_deducted' => false,
            'items' => [[
                'product_id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => 'Monobank widget',
                'qty' => 2,
                'unit_price' => 50_00,
                'line_total' => 100_00,
            ]],
        ], $overrides))->fresh();
    }
}
