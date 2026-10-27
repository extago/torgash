<?php

namespace Tests\Feature\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\Charge;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Models\Transaction;
use Torgash\Payments\PaymentFailed;
use Torgash\WayForPay\Client;
use Torgash\WayForPay\WayForPayGateway;

/**
 * WayForPay against its published protocol: HMAC-MD5 over a per-message list
 * of fields joined by `;`, and a callback that must be ANSWERED with a signed
 * `accept`, or it is redelivered for days.
 */
class WayForPayGatewayTest extends TestCase
{
    protected const ACCOUNT = 'test_merch_n1';

    protected const SECRET = 'flk3409refn54t54t*FNJRET';

    protected Product $product;

    protected PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        hook_listen('payments.gateways', fn (array $g) => $g + ['wayforpay' => WayForPayGateway::class]);

        $this->product = Product::create([
            'sku' => 'WFP-1',
            'name' => ['en' => 'WayForPay widget'],
            'slug' => 'wayforpay-widget',
            'price' => 50_00,
            'stock' => 10,
            'status' => 'active',
        ]);

        $this->method = PaymentMethod::where('enabled', true)->orderBy('id')->firstOrFail();

        Binding::create([
            'payment_method_id' => $this->method->id,
            'gateway' => 'wayforpay',
            'config' => $this->config(),
            'test_mode' => true,
        ]);
    }

    // ----------------------------------------------------------- the invoice

    public function test_an_invoice_is_signed_over_its_own_fields_and_its_link_is_returned(): void
    {
        Http::fake(['api.wayforpay.com/*' => Http::response(['reason' => 'Ok', 'reasonCode' => 1100, 'invoiceUrl' => 'https://secure.wayforpay.com/invoice/i1'])]);

        $order = $this->order();
        $url = app(WayForPayGateway::class)->start($order, Charge::for($order), $this->config());

        $this->assertSame('https://secure.wayforpay.com/invoice/i1', $url);

        Http::assertSent(function ($request) use ($order) {
            $b = $request->data();

            $expected = hash_hmac('md5', implode(';', [
                self::ACCOUNT, 'shop.example.test', $b['orderReference'], $b['orderDate'], '100', 'UAH',
                'Order '.$order->order_number, '1', '100',
            ]), self::SECRET);

            return $b['transactionType'] === 'CREATE_INVOICE'
                && $b['merchantAccount'] === self::ACCOUNT
                // A whole amount goes as an integer, so what is signed ("100")
                // is what is sent.
                && $b['amount'] === 100
                && $b['merchantSignature'] === $expected
                && preg_match('/^'.$order->id.'-\d+$/', $b['orderReference'])
                && str_ends_with($b['serviceUrl'], '/payment/wayforpay/callback')
                && $b['orderTimeout'] === 24 * 3600;
        });

        $this->assertMatchesRegularExpression('/^'.$order->id.'-/', Transaction::latest('id')->value('external_id'));
    }

    public function test_a_fractional_amount_is_sent_and_signed_the_same_way(): void
    {
        $this->assertSame(12.5, Client::amount(12_50));
        $this->assertSame('12.5', (string) Client::amount(12_50));
        $this->assertSame('12.5', json_encode(Client::amount(12_50)));
        $this->assertSame('100', json_encode(Client::amount(100_00)));
    }

    public function test_a_refused_invoice_throws_its_reason(): void
    {
        Http::fake(['*' => Http::response(['reason' => 'Invalid signature', 'reasonCode' => 1113])]);

        $this->expectException(PaymentFailed::class);
        $this->expectExceptionMessage('Invalid signature');

        app(WayForPayGateway::class)->start($o = $this->order(), Charge::for($o), $this->config());
    }

    public function test_a_currency_it_cannot_take_is_refused_before_asking(): void
    {
        Http::fake();

        $this->expectException(PaymentFailed::class);
        $this->expectExceptionMessage('PLN');

        try {
            app(WayForPayGateway::class)->start($this->order(), new Charge(100_00, 'PLN'), $this->config());
        } finally {
            Http::assertNothingSent();
        }
    }

    // --------------------------------------------------------- the callback

    public function test_a_signed_callback_pays_the_order_and_is_answered_with_a_signed_accept(): void
    {
        $order = $this->order();

        $response = $this->postCallback($this->statusDocument($order, 'Approved'))->assertOk();

        $this->assertSame(PaymentState::PAID, $order->fresh()->payment_state);
        $this->assertSame(100_00, $order->fresh()->paid_total);

        $answer = $response->json();
        $this->assertSame('accept', $answer['status']);
        $this->assertSame($order->id.'-1700000000', $answer['orderReference']);
        $this->assertSame(
            hash_hmac('md5', $answer['orderReference'].';accept;'.$answer['time'], self::SECRET),
            $answer['signature'],
        );
    }

    /** A forgery is not paid, and is not handed a sample of our signature. */
    public function test_a_forged_callback_moves_nothing_and_gets_no_signed_answer(): void
    {
        $order = $this->order();
        $body = $this->statusDocument($order, 'Approved');
        $body['merchantSignature'] = str_repeat('0', 32);

        $response = $this->postCallback($body)->assertOk();

        $this->assertSame(PaymentState::PENDING, $order->fresh()->payment_state);
        $this->assertArrayNotHasKey('signature', $response->json());
    }

    /** It arrives as JSON under a FORM content type as often as not. */
    public function test_a_json_body_under_a_form_content_type_is_still_read(): void
    {
        $order = $this->order();

        $this->call('POST', 'http://localhost/payment/wayforpay/callback', [], [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            json_encode($this->statusDocument($order, 'Approved')),
        )->assertOk();

        $this->assertSame(PaymentState::PAID, $order->fresh()->payment_state);
    }

    public function test_statuses_read_as_the_shop_means_them(): void
    {
        $cases = [
            'WaitingAuthComplete' => PaymentState::AUTHORIZED,
            'Declined' => PaymentState::FAILED,
            'Expired' => PaymentState::EXPIRED,
            'InProcessing' => PaymentState::PENDING,
            'Pending' => PaymentState::PENDING,
        ];

        foreach ($cases as $status => $expected) {
            $order = $this->order();
            $this->postCallback($this->statusDocument($order, $status))->assertOk();

            $this->assertSame($expected, $order->fresh()->payment_state, $status);
        }
    }

    public function test_a_partial_refund_is_read_from_the_refunded_amount(): void
    {
        $order = $this->order();

        $this->postCallback($this->statusDocument($order, 'Approved'))->assertOk();
        $this->postCallback($this->statusDocument($order, 'Refunded', ['refundAmount' => 40, 'processingDate' => 1700000500]))->assertOk();

        $order->refresh();
        $this->assertSame(PaymentState::PARTIALLY_REFUNDED, $order->payment_state);
        $this->assertSame(60_00, $order->paid_total);
    }

    public function test_the_same_callback_redelivered_is_one_payment(): void
    {
        $order = $this->order();

        $this->postCallback($this->statusDocument($order, 'Approved'))->assertOk();
        $this->postCallback($this->statusDocument($order, 'Approved'))->assertOk();

        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('applied', true)->count());
    }

    // ------------------------------------------------------------ the admin

    public function test_a_refund_is_signed_over_its_own_fields_and_then_believes_check_status(): void
    {
        $order = $this->order(['payment_state' => PaymentState::PAID, 'paid_total' => 100_00]);
        $this->attempt($order, $reference = $order->id.'-1700000000');

        Http::fake(fn ($request) => Http::response($request->data()['transactionType'] === 'REFUND'
            ? ['transactionStatus' => 'Refunded', 'reasonCode' => 1100, 'reason' => 'Ok']
            : ['transactionStatus' => 'Refunded', 'amount' => 100, 'refundAmount' => 30, 'orderReference' => $reference, 'currency' => 'UAH']));

        $event = app(WayForPayGateway::class)->act('refund', $order, $this->config(), ['amount' => 30_00]);

        $this->assertSame(PaymentState::PARTIALLY_REFUNDED, $event->state);
        $this->assertSame(70_00, $event->amount);

        Http::assertSent(fn ($request) => $request->data()['transactionType'] !== 'REFUND'
            || ($request->data()['amount'] === 30
                && $request->data()['merchantSignature'] === hash_hmac('md5', implode(';', [self::ACCOUNT, $reference, '30', 'UAH']), self::SECRET)));
    }

    public function test_a_declined_refund_is_logged_and_changes_nothing(): void
    {
        $order = $this->order(['payment_state' => PaymentState::PAID, 'paid_total' => 100_00]);
        $this->attempt($order, $order->id.'-1700000001');

        Http::fake(['*' => Http::response(['transactionStatus' => 'Declined', 'reason' => 'Refund amount is too big', 'reasonCode' => 1135])]);

        $this->assertNull(app(WayForPayGateway::class)->act('refund', $order, $this->config(), ['amount' => 30_00]));
        $this->assertStringContainsString('Refund amount is too big', Transaction::latest('id')->value('summary'));
    }

    // -------------------------------------------------------------- helpers

    /** @param  array<string, mixed>  $extra */
    protected function config(array $extra = []): array
    {
        return $extra + [
            'merchant_account' => self::ACCOUNT,
            'secret_key' => self::SECRET,
            'domain' => 'shop.example.test',
            'test_mode' => true,
        ];
    }

    /**
     * A status document signed exactly as WayForPay signs one.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function statusDocument(Order $order, string $status, array $extra = []): array
    {
        $body = $extra + [
            'merchantAccount' => self::ACCOUNT,
            'orderReference' => $order->id.'-1700000000',
            'amount' => 100,
            'currency' => 'UAH',
            'authCode' => '541963',
            'cardPan' => '52****7479',
            'transactionStatus' => $status,
            'reasonCode' => 1100,
            'processingDate' => 1700000100,
        ];

        $body['merchantSignature'] = hash_hmac('md5', implode(';', [
            $body['merchantAccount'], $body['orderReference'], $body['amount'], $body['currency'],
            $body['authCode'], $body['cardPan'], $body['transactionStatus'], $body['reasonCode'],
        ]), self::SECRET);

        return $body;
    }

    /** @param  array<string, mixed>  $body */
    protected function postCallback(array $body)
    {
        return $this->call('POST', 'http://localhost/payment/wayforpay/callback', [], [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body),
        );
    }

    protected function attempt(Order $order, string $reference): void
    {
        Transaction::create([
            'gateway' => 'wayforpay', 'order_id' => $order->id, 'external_id' => $reference,
            'state' => PaymentState::PENDING, 'verified' => true, 'applied' => false, 'created_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TG-WF'.random_int(10000, 99999),
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
            'payment_method_id' => $this->method->id,
            'stock_deducted' => false,
            'items' => [[
                'product_id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => 'WayForPay widget',
                'qty' => 2,
                'unit_price' => 50_00,
                'line_total' => 100_00,
            ]],
        ], $overrides))->fresh();
    }
}
