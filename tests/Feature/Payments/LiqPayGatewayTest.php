<?php

namespace Tests\Feature\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\LiqPay\Client;
use Torgash\LiqPay\LiqPayGateway;
use Torgash\Payments\Charge;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Models\Transaction;
use Torgash\Payments\PaymentFailed;

/**
 * LiqPay against its published envelope: `data` is base64 JSON, `signature`
 * is base64(sha1(private . data . private)). Every callback here is signed
 * exactly that way and posted through the real route, and a callback signed
 * with anything else must move nothing.
 */
class LiqPayGatewayTest extends TestCase
{
    protected const PUBLIC = 'i00000000001';

    protected const PRIVATE = 'test-private-key';

    protected Product $product;

    protected PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        hook_listen('payments.gateways', fn (array $g) => $g + ['liqpay' => LiqPayGateway::class]);

        $this->product = Product::create([
            'sku' => 'LIQ-1',
            'name' => ['en' => 'LiqPay widget'],
            'slug' => 'liqpay-widget',
            'price' => 50_00,
            'stock' => 10,
            'status' => 'active',
        ]);

        $this->method = PaymentMethod::where('enabled', true)->orderBy('id')->firstOrFail();

        Binding::create([
            'payment_method_id' => $this->method->id,
            'gateway' => 'liqpay',
            'config' => ['public_key' => self::PUBLIC, 'private_key' => self::PRIVATE],
            'test_mode' => false,
        ]);
    }

    // ------------------------------------------------------------- the page

    public function test_the_payment_page_is_a_signed_envelope_naming_the_order_only_inside_it(): void
    {
        $order = $this->order();
        $url = app(LiqPayGateway::class)->start($order, Charge::for($order), $this->config(['validity_hours' => 2]));

        $this->assertStringStartsWith(Client::CHECKOUT.'?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame($this->client()->sign($query['data']), $query['signature']);

        $data = Client::decode($query['data']);

        $this->assertSame(3, $data['version']);
        $this->assertSame(self::PUBLIC, $data['public_key']);
        $this->assertSame('pay', $data['action']);
        // Major units: LiqPay takes a decimal amount.
        $this->assertEquals(100.0, $data['amount']);
        $this->assertSame('UAH', $data['currency']);
        $this->assertMatchesRegularExpression('/^'.$order->id.'-\d+$/', $data['order_id']);
        // The callback URL names the gateway and nothing else — no order, no key.
        $this->assertStringEndsWith('/payment/liqpay/callback', $data['server_url']);
        $this->assertArrayNotHasKey('sandbox', $data);
        $this->assertArrayHasKey('expired_date', $data);
        $this->assertStringNotContainsString(self::PRIVATE, $url);

        $this->assertSame($data['order_id'], Transaction::latest('id')->value('external_id'));
    }

    public function test_hold_and_test_mode_reach_the_envelope(): void
    {
        $order = $this->order();
        $url = app(LiqPayGateway::class)->start($order, Charge::for($order), $this->config(['hold' => true, 'test_mode' => true]));

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $data = Client::decode($query['data']);

        $this->assertSame('hold', $data['action']);
        $this->assertSame(1, $data['sandbox']);
    }

    /** The binding's test switch reaches the gateway — for LiqPay it is a real flag. */
    public function test_the_bindings_test_switch_is_part_of_what_a_gateway_is_given(): void
    {
        $binding = Binding::where('gateway', 'liqpay')->firstOrFail();
        $this->assertFalse($binding->gatewayConfig()['test_mode']);

        $binding->update(['test_mode' => true]);
        $this->assertTrue($binding->fresh()->gatewayConfig()['test_mode']);
        $this->assertSame(self::PUBLIC, $binding->fresh()->gatewayConfig()['public_key']);
    }

    public function test_a_currency_liqpay_cannot_take_is_refused_with_a_reason(): void
    {
        $this->expectException(PaymentFailed::class);
        $this->expectExceptionMessage('PLN');

        app(LiqPayGateway::class)->start($this->order(), new Charge(100_00, 'PLN'), $this->config());
    }

    public function test_a_sandbox_key_without_test_mode_is_warned_about_and_so_is_the_reverse(): void
    {
        $gateway = app(LiqPayGateway::class);

        $this->assertCount(1, $gateway->warnings(['public_key' => 'sandbox_i123', 'test_mode' => false]));
        $this->assertCount(1, $gateway->warnings(['public_key' => 'i123', 'test_mode' => true]));
        $this->assertSame([], $gateway->warnings(['public_key' => 'sandbox_i123', 'test_mode' => true]));
        $this->assertSame([], $gateway->warnings(['public_key' => 'i123', 'test_mode' => false]));
    }

    // --------------------------------------------------------- the callback

    public function test_a_properly_signed_callback_pays_the_order(): void
    {
        $order = $this->order();

        $this->postCallback($order, ['status' => 'success', 'amount' => 100])->assertOk();

        $order->refresh();
        $this->assertSame(PaymentState::PAID, $order->payment_state);
        $this->assertSame(100_00, $order->paid_total);
    }

    public function test_a_callback_signed_with_another_key_moves_nothing(): void
    {
        $order = $this->order();
        $data = $this->data($order, ['status' => 'success', 'amount' => 100]);
        $forged = base64_encode(sha1('guess'.$data.'guess', true));

        $this->post('http://localhost/payment/liqpay/callback', ['data' => $data, 'signature' => $forged])->assertOk();

        $this->assertSame(PaymentState::PENDING, $order->fresh()->payment_state);
        $this->assertFalse(Transaction::latest('id')->firstOrFail()->verified);
    }

    /**
     * The order is read from the SIGNED data. The reference add-on reads it
     * from the callback URL, so a signature about one order paid another.
     */
    public function test_the_order_comes_from_the_signed_data_not_the_url(): void
    {
        $paid = $this->order();
        $other = $this->order();

        $data = $this->data($paid, ['status' => 'success', 'amount' => 100]);

        $this->post('http://localhost/payment/liqpay/callback?order_id='.$other->id, [
            'data' => $data,
            'signature' => $this->client()->sign($data),
        ])->assertOk();

        $this->assertSame(PaymentState::PAID, $paid->fresh()->payment_state);
        $this->assertSame(PaymentState::PENDING, $other->fresh()->payment_state);
    }

    public function test_statuses_read_as_the_shop_means_them(): void
    {
        $cases = [
            'hold_wait' => PaymentState::AUTHORIZED,
            'sandbox' => PaymentState::PAID,
            // The money is taken; LiqPay is only still checking the shop.
            'wait_accept' => PaymentState::PAID,
            'failure' => PaymentState::FAILED,
            'processing' => PaymentState::PENDING,
        ];

        foreach ($cases as $status => $expected) {
            $order = $this->order();
            $this->postCallback($order, ['status' => $status, 'amount' => 100, 'payment_id' => 'p-'.$status])->assertOk();

            $this->assertSame($expected, $order->fresh()->payment_state, $status);
        }
    }

    public function test_a_partial_refund_is_read_from_the_refunded_amount(): void
    {
        $order = $this->order();

        $this->postCallback($order, ['status' => 'success', 'amount' => 100])->assertOk();
        $this->postCallback($order, ['status' => 'reversed', 'amount' => 100, 'refund_amount' => 40])->assertOk();

        $order->refresh();
        $this->assertSame(PaymentState::PARTIALLY_REFUNDED, $order->payment_state);
        $this->assertSame(60_00, $order->paid_total);
    }

    public function test_the_same_callback_delivered_twice_is_one_payment(): void
    {
        $order = $this->order();

        $this->postCallback($order, ['status' => 'success', 'amount' => 100])->assertOk();
        $this->postCallback($order, ['status' => 'success', 'amount' => 100])->assertOk();

        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('applied', true)->count());
    }

    // ------------------------------------------------------------ the admin

    public function test_capturing_a_hold_completes_it_and_then_believes_liqpay(): void
    {
        $order = $this->order(['payment_state' => PaymentState::AUTHORIZED, 'paid_total' => 100_00]);
        $this->attempt($order, $reference = $order->id.'-1700000000');

        Http::fake(function ($request) use ($reference) {
            $data = Client::decode($request->data()['data']);

            return Http::response($data['action'] === 'status'
                ? ['result' => 'ok', 'status' => 'success', 'amount' => 100, 'order_id' => $reference]
                : ['result' => 'ok', 'status' => 'success']);
        });

        $event = app(LiqPayGateway::class)->act('capture', $order, $this->config(), ['amount' => 100_00]);

        $this->assertSame(PaymentState::PAID, $event->state);
        $this->assertSame($order->id, $event->orderId);

        Http::assertSent(function ($request) use ($reference) {
            $data = Client::decode($request->data()['data']);

            return $data['action'] !== 'hold_completion'
                || ($data['order_id'] === $reference && (float) $data['amount'] === 100.0
                    && $request->data()['signature'] === $this->client()->sign($request->data()['data']));
        });
    }

    /** "reversed" with no amount after a PARTIAL refund is still partial. */
    public function test_a_partial_refund_from_the_order_screen_stays_partial(): void
    {
        $order = $this->order(['payment_state' => PaymentState::PAID, 'paid_total' => 100_00]);
        $this->attempt($order, $order->id.'-1700000001');

        Http::fake(fn ($request) => Http::response(
            Client::decode($request->data()['data'])['action'] === 'status'
                ? ['result' => 'ok', 'status' => 'reversed', 'amount' => 100]
                : ['result' => 'ok', 'status' => 'reversed'],
        ));

        $event = app(LiqPayGateway::class)->act('refund', $order, $this->config(), ['amount' => 30_00]);

        $this->assertSame(PaymentState::PARTIALLY_REFUNDED, $event->state);
        $this->assertSame(70_00, $event->amount);
    }

    public function test_a_refused_refund_is_logged_and_changes_nothing(): void
    {
        $order = $this->order(['payment_state' => PaymentState::PAID, 'paid_total' => 100_00]);
        $this->attempt($order, $order->id.'-1700000002');

        Http::fake(['*' => Http::response(['result' => 'error', 'err_description' => 'Refund is not possible'])]);

        $this->assertNull(app(LiqPayGateway::class)->act('refund', $order, $this->config(), ['amount' => 30_00]));
        $this->assertStringContainsString('Refund is not possible', Transaction::latest('id')->value('summary'));
    }

    // -------------------------------------------------------------- helpers

    protected function client(): Client
    {
        return new Client(self::PUBLIC, self::PRIVATE);
    }

    /** @param  array<string, mixed>  $extra */
    protected function config(array $extra = []): array
    {
        return $extra + ['public_key' => self::PUBLIC, 'private_key' => self::PRIVATE, 'test_mode' => false];
    }

    /** @param  array<string, mixed>  $fields */
    protected function data(Order $order, array $fields): string
    {
        return base64_encode(json_encode($fields + [
            'order_id' => $order->id.'-1700000000',
            'payment_id' => 123456,
            'currency' => 'UAH',
            'action' => 'pay',
            'public_key' => self::PUBLIC,
        ]));
    }

    /** @param  array<string, mixed>  $fields */
    protected function postCallback(Order $order, array $fields)
    {
        $data = $this->data($order, $fields);

        return $this->post('http://localhost/payment/liqpay/callback', [
            'data' => $data,
            'signature' => $this->client()->sign($data),
        ]);
    }

    /** The page the customer was sent to, as start() records it. */
    protected function attempt(Order $order, string $reference): void
    {
        Transaction::create([
            'gateway' => 'liqpay', 'order_id' => $order->id, 'external_id' => $reference,
            'state' => PaymentState::PENDING, 'verified' => true, 'applied' => false, 'created_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TG-LP'.random_int(10000, 99999),
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
                'name' => 'LiqPay widget',
                'qty' => 2,
                'unit_price' => 50_00,
                'line_total' => 100_00,
            ]],
        ], $overrides))->fresh();
    }
}
